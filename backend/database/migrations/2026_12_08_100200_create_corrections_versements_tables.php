<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Correction des versements par contre-passation liée (ADR-0015, spec module-4-scolarite-espace-financier §8,
 * sous-lot F4a). Rien n'est réécrit :
 *
 * - `corrections_versements` : la pièce de correction, en ajout seul, numérotée COR-AAAA-NNNNN sans trou ;
 * - `recus_versements.remplace_recu_id` : le reçu annulé que ce reçu remplace, écrit à l'insertion ;
 * - `confirmations_versements.correction_id` : une confirmation reportée par une correction lit son scan sur la
 *   confirmation d'origine ;
 * - `demandes_autorisation` : type `corriger_versement`, ses paramètres exigent la nature et la catégorie.
 *
 * SQL brut (ADR-0011 et 0013, point 7) : contraintes CHECK, déclencheurs d'immuabilité et de troncature, comme pour
 * les autres tables d'argent. `down()` échoue dès qu'une correction existe : une pièce ne se supprime pas.
 */
return new class extends Migration
{
    private const TYPES_AVANT = "'rouvrir_etape_saisie', 'valider_quota', 'valider_maquette', 'valider_autre', 'annuler_pointage', 'revoquer_document', 'rejeter_etat_paie', 'deverrouiller_compte', 'accorder_moratoire'";

    private const CONFIRMATION_AVANT = 'reprise OR (confirme_par IS NOT NULL AND justificatif_path IS NOT NULL AND attestation)';

    public function up(): void
    {
        Schema::create('compteurs_corrections', function (Blueprint $table): void {
            $table->unsignedSmallInteger('annee')->primary();
            $table->unsignedInteger('dernier')->default(0);
        });

        Schema::create('corrections_versements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('numero', 20)->unique();
            $table->unsignedSmallInteger('annee_numero');
            $table->unsignedInteger('sequence');
            $table->string('nature', 20);
            $table->string('categorie', 30);
            $table->foreignId('versement_annule_id')->unique()->constrained('versements')->restrictOnDelete();
            $table->foreignId('versement_remplacant_id')->nullable()->unique()->constrained('versements')->restrictOnDelete();
            $table->foreignId('annulation_recu_id')->nullable()->constrained('annulations_recus')->restrictOnDelete();
            $table->foreignId('recu_remplacant_id')->nullable()->constrained('recus_versements')->restrictOnDelete();
            $table->jsonb('champs');
            $table->string('motif', 2000);
            $table->foreignId('demande_id')->nullable()->constrained('demandes_autorisation')->restrictOnDelete();
            $table->foreignId('demandee_par')->constrained('users')->restrictOnDelete();
            $table->foreignId('executee_par')->constrained('users')->restrictOnDelete();
            $table->boolean('rectification_immediate')->default(false);
            $table->string('confirmation_reportee', 10)->nullable();
            $table->timestampTz('created_at');

            $table->unique(['annee_numero', 'sequence']);
            $table->index('annulation_recu_id');
            $table->index('recu_remplacant_id');
            $table->index('demande_id');
            $table->index('demandee_par');
            $table->index('executee_par');
        });

        DB::statement("ALTER TABLE corrections_versements ADD CONSTRAINT corrections_versements_check CHECK (
            numero ~ '^COR-[0-9]{4}-[0-9]{5}$'
            AND nature IN ('remplacement', 'annulation')
            AND categorie IN ('montant', 'auditeur', 'reference', 'date', 'mode', 'payeur', 'doublon', 'rejet_banque', 'autre')
            AND ((nature = 'remplacement') = (versement_remplacant_id IS NOT NULL))
            AND (versement_remplacant_id IS NULL OR versement_remplacant_id <> versement_annule_id)
            AND jsonb_typeof(champs) = 'object'
            AND (nature = 'annulation' OR champs <> '{}'::jsonb)
            AND length(trim(motif)) >= 10
            AND (confirmation_reportee IS NULL OR confirmation_reportee IN ('office', 'attestee'))
            AND (NOT rectification_immediate OR (nature = 'remplacement' AND demande_id IS NULL AND confirmation_reportee IS NULL))
        )");
        // Pièce comptable : jamais modifiée ni supprimée, même hors de l'application ; ni tronquée. Fonction propre à la
        // table : le retour arrière des migrations du lot 4 et de D1 supprime les leurs.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION corrections_versements_immuables() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'Pièce de correction immuable (%) : elle ne se modifie ni ne se supprime', TG_OP USING ERRCODE = 'check_violation';
            END $$;
            CREATE TRIGGER corrections_versements_immuables BEFORE UPDATE OR DELETE OR TRUNCATE ON corrections_versements
              FOR EACH STATEMENT EXECUTE FUNCTION corrections_versements_immuables();
            SQL);

        Schema::table('recus_versements', function (Blueprint $table): void {
            $table->foreignId('remplace_recu_id')->nullable()->constrained('recus_versements')->restrictOnDelete();
            $table->index('remplace_recu_id');
        });
        DB::statement('CREATE UNIQUE INDEX recus_versements_remplace_unique ON recus_versements (remplace_recu_id) WHERE remplace_recu_id IS NOT NULL');

        Schema::table('confirmations_versements', function (Blueprint $table): void {
            $table->foreignId('correction_id')->nullable()->constrained('corrections_versements')->restrictOnDelete();
            $table->index('correction_id');
        });
        DB::statement('ALTER TABLE confirmations_versements DROP CONSTRAINT confirmations_versements_completes');
        DB::statement('ALTER TABLE confirmations_versements ADD CONSTRAINT confirmations_versements_completes CHECK ('
            .'reprise OR (correction_id IS NOT NULL AND confirme_par IS NOT NULL) OR (confirme_par IS NOT NULL AND justificatif_path IS NOT NULL AND attestation))');

        DB::statement('ALTER TABLE demandes_autorisation DROP CONSTRAINT demandes_autorisation_check');
        DB::statement($this->contrainteTypes(self::TYPES_AVANT.", 'corriger_versement'"));
        DB::statement("ALTER TABLE demandes_autorisation ADD CONSTRAINT demandes_autorisation_correction_check CHECK (
            type <> 'corriger_versement' OR (
                coalesce(parametres->>'nature', '') IN ('remplacement', 'annulation')
                AND coalesce(parametres->>'categorie', '') <> ''
            )
        )");
    }

    public function down(): void
    {
        if (DB::table('corrections_versements')->exists() || DB::table('demandes_autorisation')->where('type', 'corriger_versement')->exists()) {
            throw new RuntimeException('Des corrections de versement existent : ce sont des pièces. Revenez au code précédent sans annuler la migration.');
        }

        DB::statement('ALTER TABLE demandes_autorisation DROP CONSTRAINT demandes_autorisation_correction_check');
        DB::statement('ALTER TABLE demandes_autorisation DROP CONSTRAINT demandes_autorisation_check');
        DB::statement($this->contrainteTypes(self::TYPES_AVANT));

        DB::statement('ALTER TABLE confirmations_versements DROP CONSTRAINT confirmations_versements_completes');
        DB::statement('ALTER TABLE confirmations_versements ADD CONSTRAINT confirmations_versements_completes CHECK ('.self::CONFIRMATION_AVANT.')');
        Schema::table('confirmations_versements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('correction_id');
        });

        DB::statement('DROP INDEX IF EXISTS recus_versements_remplace_unique');
        Schema::table('recus_versements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('remplace_recu_id');
        });

        Schema::dropIfExists('corrections_versements');
        DB::unprepared('DROP FUNCTION IF EXISTS corrections_versements_immuables()');
        Schema::dropIfExists('compteurs_corrections');
    }

    private function contrainteTypes(string $types): string
    {
        return "ALTER TABLE demandes_autorisation ADD CONSTRAINT demandes_autorisation_check CHECK (
            numero ~ '^DM-[0-9]{4}-[0-9]{5}$'
            AND type IN ({$types})
            AND statut IN ('soumise', 'visa_attendu', 'rejetee', 'executee', 'echec_execution', 'retiree')
            AND length(trim(motif)) >= 10
            AND (empreinte IS NULL OR empreinte ~ '^[0-9a-f]{64}$')
        )";
    }
};
