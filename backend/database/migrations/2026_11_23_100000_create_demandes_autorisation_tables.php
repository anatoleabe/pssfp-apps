<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Lot 14c (D31) : demandes d'autorisation et leurs décisions. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demandes_autorisation', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('numero', 20)->unique();
            $table->unsignedSmallInteger('annee');
            $table->unsignedInteger('sequence');
            $table->string('type', 40);
            $table->foreignId('demandeur_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('entite_id')->nullable();
            $table->string('objet', 300);
            // Empreinte de l'objet visé à la soumission : l'exécution refuse un objet modifié depuis.
            $table->char('empreinte', 64)->nullable();
            $table->string('motif', 2000);
            $table->string('statut', 20)->default('soumise');
            $table->timestampTz('soumise_le');
            $table->timestampTz('echeance');
            $table->timestampTz('traitee_le')->nullable();
            $table->string('resultat', 1000)->nullable();

            $table->unique(['annee', 'sequence']);
            $table->index(['statut', 'echeance']);
            $table->index(['demandeur_id', 'soumise_le']);
            $table->index(['type', 'entite_id']);
        });

        Schema::create('decisions_demandes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('demande_id')->constrained('demandes_autorisation')->restrictOnDelete();
            $table->foreignId('decideur_id')->constrained('users')->restrictOnDelete();
            $table->string('qualite', 20);
            $table->string('decision', 10);
            $table->string('commentaire', 1000)->nullable();
            $table->timestampTz('decide_le');

            $table->unique(['demande_id', 'qualite']);
            $table->index('decideur_id');
        });

        // SQL brut, ADR-0011 : listes fermées, motif, décisions en ajout seul.
        DB::statement("ALTER TABLE demandes_autorisation ADD CONSTRAINT demandes_autorisation_check CHECK (
            numero ~ '^DM-[0-9]{4}-[0-9]{5}$'
            AND type IN ('rouvrir_etape_saisie', 'valider_quota', 'valider_maquette', 'valider_autre', 'annuler_pointage', 'revoquer_document', 'rejeter_etat_paie')
            AND statut IN ('soumise', 'visa_attendu', 'rejetee', 'executee', 'echec_execution', 'retiree')
            AND length(trim(motif)) >= 10
            AND (empreinte IS NULL OR empreinte ~ '^[0-9a-f]{64}$')
        )");
        // Une seule demande en cours par objet, même sous deux soumissions simultanées.
        DB::statement("CREATE UNIQUE INDEX demandes_autorisation_en_cours ON demandes_autorisation (type, entite_id)
            WHERE statut IN ('soumise', 'visa_attendu') AND entite_id IS NOT NULL");
        DB::statement("ALTER TABLE decisions_demandes ADD CONSTRAINT decisions_demandes_check CHECK (
            qualite IN ('responsable', 'controle_interne') AND decision IN ('approuve', 'rejete')
            AND (decision = 'approuve' OR length(trim(coalesce(commentaire, ''))) >= 10)
        )");
        DB::unprepared(<<<'SQL'
            -- Ce qui a été demandé ne change plus ; le statut ne suit que les transitions prévues.
            CREATE OR REPLACE FUNCTION demandes_autorisation_figees() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'Demande en ajout seul : elle ne se supprime pas' USING ERRCODE = 'check_violation';
              END IF;
              IF NEW.uuid <> OLD.uuid OR NEW.numero <> OLD.numero OR NEW.type <> OLD.type OR NEW.demandeur_id <> OLD.demandeur_id
                 OR NEW.entite_id IS DISTINCT FROM OLD.entite_id OR NEW.objet <> OLD.objet OR NEW.motif <> OLD.motif
                 OR NEW.empreinte IS DISTINCT FROM OLD.empreinte OR NEW.soumise_le <> OLD.soumise_le OR NEW.echeance <> OLD.echeance THEN
                RAISE EXCEPTION 'Demande figée : seuls son statut et son résultat évoluent' USING ERRCODE = 'check_violation';
              END IF;
              IF NEW.statut <> OLD.statut AND NOT (
                   (OLD.statut = 'soumise' AND NEW.statut IN ('visa_attendu', 'rejetee', 'executee', 'echec_execution', 'retiree'))
                OR (OLD.statut = 'visa_attendu' AND NEW.statut IN ('rejetee', 'executee', 'echec_execution'))) THEN
                RAISE EXCEPTION 'Transition de statut interdite : % vers %', OLD.statut, NEW.statut USING ERRCODE = 'check_violation';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER demandes_autorisation_figees BEFORE UPDATE OR DELETE ON demandes_autorisation
              FOR EACH ROW EXECUTE FUNCTION demandes_autorisation_figees();

            CREATE OR REPLACE FUNCTION decisions_demandes_ajout_seul() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'Décision en ajout seul' USING ERRCODE = 'check_violation';
            END $$;
            CREATE TRIGGER decisions_demandes_ajout_seul BEFORE UPDATE OR DELETE ON decisions_demandes
              FOR EACH ROW EXECUTE FUNCTION decisions_demandes_ajout_seul();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('decisions_demandes');
        Schema::dropIfExists('demandes_autorisation');
        DB::unprepared('DROP FUNCTION IF EXISTS decisions_demandes_ajout_seul()');
        DB::unprepared('DROP FUNCTION IF EXISTS demandes_autorisation_figees()');
    }
};
