<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Compte de l'auditeur, sous-lot D1 (spec module-4-scolarite-comptes-d1 §5 ; ADR-0013).
 *
 * - Les versements se rattachent à l'auditeur (`auditeur_id`, renseigné depuis l'inscription).
 * - Chaque part d'un versement qui règle une inscription est une imputation enregistrée,
 *   jamais modifiée ni supprimée : on l'annule, une fois.
 * - Des clés composites gardent versement, inscription et imputation chez le même auditeur.
 * - Un déclencheur de contrainte vérifie, à la fin de chaque instruction, qu'aucun versement
 *   n'est imputé au-delà de son montant (ni après son annulation) et qu'aucune inscription
 *   ne l'est au-delà de son dû. SQL brut autorisé par l'ADR-0013, point 7.
 *
 * Exécutée dans une transaction (PostgreSQL) : tout ou rien. La reprise des versements
 * existants est la migration suivante.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1 à 3 : le versement appartient à l'auditeur de son inscription.
        Schema::table('versements', function (Blueprint $table): void {
            $table->foreignId('auditeur_id')->nullable()->after('uuid')->constrained('auditeurs')->restrictOnDelete();
            $table->index('auditeur_id');
        });
        DB::statement('UPDATE versements v SET auditeur_id = i.auditeur_id FROM inscriptions_annuelles i WHERE i.id = v.inscription_annuelle_id AND v.auditeur_id IS NULL');
        DB::statement('ALTER TABLE versements ALTER COLUMN auditeur_id SET NOT NULL');

        // 4 : cibles des clés composites, puis le versement ne désigne qu'une inscription de son auditeur.
        Schema::table('inscriptions_annuelles', function (Blueprint $table): void {
            $table->unique(['id', 'auditeur_id'], 'inscriptions_annuelles_id_auditeur_unique');
        });
        Schema::table('versements', function (Blueprint $table): void {
            $table->unique(['id', 'auditeur_id'], 'versements_id_auditeur_unique');
            $table->foreign(['inscription_annuelle_id', 'auditeur_id'], 'versements_inscription_auditeur_foreign')
                ->references(['id', 'auditeur_id'])->on('inscriptions_annuelles')->restrictOnDelete();
        });

        // 5 : les imputations.
        Schema::create('imputations_versements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('auditeur_id')->constrained('auditeurs')->restrictOnDelete();
            $table->unsignedBigInteger('versement_id');
            $table->unsignedBigInteger('inscription_annuelle_id');
            $table->integer('montant');
            $table->string('origine', 10);
            // RESTRICT et non SET NULL : une mise à nul modifierait la ligne, ce que l'immuabilité refuse.
            $table->foreignId('remplace_id')->nullable()->constrained('imputations_versements')->restrictOnDelete();
            $table->foreignId('cree_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('annule_at')->nullable();
            $table->foreignId('annule_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('annule_motif', 500)->nullable();
            $table->timestampsTz();

            $table->foreign(['versement_id', 'auditeur_id'], 'imputations_versements_versement_auditeur_foreign')
                ->references(['id', 'auditeur_id'])->on('versements')->restrictOnDelete();
            $table->foreign(['inscription_annuelle_id', 'auditeur_id'], 'imputations_versements_inscription_auditeur_foreign')
                ->references(['id', 'auditeur_id'])->on('inscriptions_annuelles')->restrictOnDelete();
            $table->index('auditeur_id');
            $table->index('remplace_id');
            $table->index('cree_par');
            $table->index('annule_par');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE imputations_versements ADD CONSTRAINT imputations_versements_check CHECK (
              montant > 0
              AND origine IN ('saisie', 'avance', 'reprise')
              AND (
                (annule_at IS NULL AND annule_par IS NULL AND annule_motif IS NULL)
                OR (annule_at IS NOT NULL AND annule_motif IS NOT NULL AND length(trim(annule_motif)) >= 5)
              )
            )
            SQL);
        DB::statement('CREATE INDEX imputations_versements_versement_actives ON imputations_versements (versement_id) WHERE annule_at IS NULL');
        DB::statement('CREATE INDEX imputations_versements_inscription_actives ON imputations_versements (inscription_annuelle_id) WHERE annule_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX imputations_versements_remplace_unique ON imputations_versements (remplace_id) WHERE remplace_id IS NOT NULL');

        // Immuabilité : seule l'annulation est permise, une fois.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION imputations_immuables() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'Imputation immuable : annulez-la, ne la supprimez pas' USING ERRCODE = 'check_violation';
              END IF;
              IF OLD.annule_at IS NOT NULL OR NEW.annule_at IS NULL
                 OR ROW(NEW.id, NEW.uuid, NEW.auditeur_id, NEW.versement_id, NEW.inscription_annuelle_id, NEW.montant,
                        NEW.origine, NEW.remplace_id, NEW.cree_par, NEW.created_at)
                    IS DISTINCT FROM ROW(OLD.id, OLD.uuid, OLD.auditeur_id, OLD.versement_id, OLD.inscription_annuelle_id, OLD.montant,
                        OLD.origine, OLD.remplace_id, OLD.cree_par, OLD.created_at) THEN
                RAISE EXCEPTION 'Imputation immuable : seule son annulation, une fois, est permise' USING ERRCODE = 'check_violation';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER imputations_versements_immuables BEFORE UPDATE OR DELETE ON imputations_versements
              FOR EACH ROW EXECUTE FUNCTION imputations_immuables();
            SQL);

        // Cohérence du compte, vérifiée à la fin de chaque instruction (reportable, mais immédiate par défaut).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION compte_auditeur_coherent() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE v_id bigint; i_id bigint;
            BEGIN
              IF TG_TABLE_NAME = 'imputations_versements' THEN
                v_id := NEW.versement_id; i_id := NEW.inscription_annuelle_id;
              ELSIF TG_TABLE_NAME = 'versements' THEN
                v_id := NEW.id;
              ELSE
                i_id := NEW.id;
              END IF;
              IF v_id IS NOT NULL AND EXISTS (
                SELECT 1 FROM versements v WHERE v.id = v_id
                  AND (SELECT COALESCE(SUM(x.montant), 0) FROM imputations_versements x
                         WHERE x.versement_id = v.id AND x.annule_at IS NULL)
                      > CASE WHEN v.annule_at IS NULL THEN v.montant ELSE 0 END) THEN
                RAISE EXCEPTION 'Compte incohérent : versement % imputé au-delà de son montant, ou annulé avec des imputations actives', v_id
                  USING ERRCODE = 'check_violation';
              END IF;
              IF i_id IS NOT NULL AND EXISTS (
                SELECT 1 FROM inscriptions_annuelles i WHERE i.id = i_id
                  AND (SELECT COALESCE(SUM(x.montant), 0) FROM imputations_versements x
                         WHERE x.inscription_annuelle_id = i.id AND x.annule_at IS NULL)
                      > i.montant_inscription + i.montant_tranche1 + i.montant_tranche2 - i.remise_montant) THEN
                RAISE EXCEPTION 'Compte incohérent : inscription % imputée au-delà de son dû', i_id USING ERRCODE = 'check_violation';
              END IF;
              RETURN NULL;
            END $$;
            CREATE CONSTRAINT TRIGGER imputations_coherentes
              AFTER INSERT OR UPDATE ON imputations_versements
              DEFERRABLE INITIALLY IMMEDIATE FOR EACH ROW EXECUTE FUNCTION compte_auditeur_coherent();
            CREATE CONSTRAINT TRIGGER versements_compte_coherent
              AFTER UPDATE OF montant, annule_at ON versements
              DEFERRABLE INITIALLY IMMEDIATE FOR EACH ROW EXECUTE FUNCTION compte_auditeur_coherent();
            CREATE CONSTRAINT TRIGGER inscriptions_compte_coherent
              AFTER UPDATE OF montant_inscription, montant_tranche1, montant_tranche2, remise_montant ON inscriptions_annuelles
              DEFERRABLE INITIALLY IMMEDIATE FOR EACH ROW EXECUTE FUNCTION compte_auditeur_coherent();
            SQL);

        // 6 : l'objet d'un reçu détaille plusieurs années. Élargir un varchar ne réécrit aucune
        // ligne et ne déclenche pas l'immuabilité des reçus : les reçus émis ne changent pas.
        DB::statement('ALTER TABLE recus_versements ALTER COLUMN objet TYPE varchar(500)');
    }

    public function down(): void
    {
        // Retour arrière par le code, une fois le compte en service (précédent des tables du lot 1).
        if (Schema::hasTable('imputations_versements') && DB::table('imputations_versements')->where('origine', '<>', 'reprise')->exists()) {
            throw new RuntimeException('Le compte a servi : revenez au code précédent sans annuler la migration.');
        }
        if (DB::table('recus_versements')->whereRaw('length(objet) > 255')->exists()) {
            throw new RuntimeException('Un reçu émis a un objet de plus de 255 caractères : la colonne ne se réduit pas. Revenez au code précédent sans annuler la migration.');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS inscriptions_compte_coherent ON inscriptions_annuelles;
            DROP TRIGGER IF EXISTS versements_compte_coherent ON versements;
            SQL);
        Schema::dropIfExists('imputations_versements');
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS compte_auditeur_coherent();
            DROP FUNCTION IF EXISTS imputations_immuables();
            SQL);

        Schema::table('versements', function (Blueprint $table): void {
            $table->dropForeign('versements_inscription_auditeur_foreign');
            $table->dropUnique('versements_id_auditeur_unique');
        });
        Schema::table('inscriptions_annuelles', function (Blueprint $table): void {
            $table->dropUnique('inscriptions_annuelles_id_auditeur_unique');
        });
        Schema::table('versements', function (Blueprint $table): void {
            $table->dropForeign(['auditeur_id']);
            $table->dropIndex(['auditeur_id']);
            $table->dropColumn('auditeur_id');
        });

        DB::statement('ALTER TABLE recus_versements ALTER COLUMN objet TYPE varchar(255)');
    }
};
