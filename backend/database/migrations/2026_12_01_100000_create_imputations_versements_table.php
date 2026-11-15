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
 *   ne l'est au-delà de son dû. Il prend d'abord le verrou de l'auditeur : un écrivain qui
 *   passerait à côté de l'application est sérialisé comme elle (au pire, un interblocage
 *   détecté par PostgreSQL, jamais une somme fausse).
 * - Les versements deviennent immuables en base : seuls l'annulation et le bordereau, une fois
 *   chacun ; ni suppression ni troncature (l'avance du compte en dépend entièrement).
 * SQL brut autorisé par l'ADR-0013, point 7.
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

        // Immuabilité : seule l'annulation est permise, une fois. Toute autre colonne est figée, y compris
        // une colonne ajoutée plus tard (comparaison de la ligne entière, hors colonnes de l'annulation).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION imputations_immuables() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE annulation text[] := ARRAY['annule_at', 'annule_par', 'annule_motif', 'updated_at'];
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'Imputation immuable : annulez-la, ne la supprimez pas' USING ERRCODE = 'check_violation';
              END IF;
              IF OLD.annule_at IS NOT NULL OR NEW.annule_at IS NULL
                 OR (to_jsonb(NEW) - annulation) IS DISTINCT FROM (to_jsonb(OLD) - annulation) THEN
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
              -- Verrou de l'auditeur (R8) : déjà tenu par l'application, il sérialise tout autre écrivain ;
              -- la somme qui suit, lue après lui, voit les écritures validées entre-temps.
              PERFORM 1 FROM auditeurs a WHERE a.id = NEW.auditeur_id FOR UPDATE;
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

        // Versements immuables : l'avance (versements valides − imputations actives) en dépend. Seuls permis :
        // l'annulation, une fois, depuis un versement valide ; le bordereau, joint une fois ; la mise à nul
        // d'un auteur par la seule suppression de son compte (clés SET NULL du lot 1, déclencheur imbriqué :
        // pg_trigger_depth() > 1). Toute autre colonne est figée, y compris une colonne ajoutée plus tard.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION versements_immuables() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE libres text[] := ARRAY['annule_at', 'annule_par', 'annule_motif', 'justificatif_path', 'saisi_par', 'updated_at'];
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'Versement immuable : annulez-le, ne le supprimez pas' USING ERRCODE = 'check_violation';
              END IF;
              IF (to_jsonb(NEW) - libres) IS DISTINCT FROM (to_jsonb(OLD) - libres)
                 OR (NEW.saisi_par IS DISTINCT FROM OLD.saisi_par AND (NEW.saisi_par IS NOT NULL OR pg_trigger_depth() < 2))
                 OR (NEW.justificatif_path IS DISTINCT FROM OLD.justificatif_path AND OLD.justificatif_path IS NOT NULL)
                 OR (OLD.annule_at IS NOT NULL AND (NEW.annule_at IS DISTINCT FROM OLD.annule_at OR NEW.annule_motif IS DISTINCT FROM OLD.annule_motif
                     OR (NEW.annule_par IS DISTINCT FROM OLD.annule_par AND (NEW.annule_par IS NOT NULL OR pg_trigger_depth() < 2))))
                 OR (OLD.annule_at IS NULL AND NEW.annule_at IS NULL
                     AND ROW(NEW.annule_par, NEW.annule_motif) IS DISTINCT FROM ROW(OLD.annule_par, OLD.annule_motif)) THEN
                RAISE EXCEPTION 'Versement immuable : seuls son annulation et son bordereau, une fois chacun, sont permis' USING ERRCODE = 'check_violation';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER versements_immuables BEFORE UPDATE OR DELETE ON versements
              FOR EACH ROW EXECUTE FUNCTION versements_immuables();
            CREATE OR REPLACE FUNCTION compte_sans_troncature() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'Table d''argent (%) : aucune troncature', TG_TABLE_NAME USING ERRCODE = 'check_violation';
            END $$;
            CREATE TRIGGER versements_sans_troncature BEFORE TRUNCATE ON versements
              FOR EACH STATEMENT EXECUTE FUNCTION compte_sans_troncature();
            CREATE TRIGGER imputations_versements_sans_troncature BEFORE TRUNCATE ON imputations_versements
              FOR EACH STATEMENT EXECUTE FUNCTION compte_sans_troncature();
            SQL);

        // 6 : l'objet d'un reçu détaille plusieurs années. Élargir un varchar ne réécrit aucune
        // ligne et ne déclenche pas l'immuabilité des reçus : les reçus émis ne changent pas.
        DB::statement('ALTER TABLE recus_versements ALTER COLUMN objet TYPE varchar(500)');
    }

    public function down(): void
    {
        // Retour arrière par le code, une fois le compte en service (précédent des tables du lot 1) : une imputation
        // d'une autre origine que la reprise, ou une imputation de reprise annulée ou remplacée, est de l'histoire du compte.
        if (Schema::hasTable('imputations_versements') && DB::table('imputations_versements')
            ->where(fn ($q) => $q->where('origine', '<>', 'reprise')->orWhereNotNull('annule_at')->orWhereNotNull('remplace_id'))->exists()) {
            throw new RuntimeException('Le compte a servi : revenez au code précédent sans annuler la migration.');
        }
        if (DB::table('recus_versements')->whereRaw('length(objet) > 255')->exists()) {
            throw new RuntimeException('Un reçu émis a un objet de plus de 255 caractères : la colonne ne se réduit pas. Revenez au code précédent sans annuler la migration.');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS inscriptions_compte_coherent ON inscriptions_annuelles;
            DROP TRIGGER IF EXISTS versements_compte_coherent ON versements;
            DROP TRIGGER IF EXISTS versements_immuables ON versements;
            DROP TRIGGER IF EXISTS versements_sans_troncature ON versements;
            SQL);
        Schema::dropIfExists('imputations_versements');
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS compte_auditeur_coherent();
            DROP FUNCTION IF EXISTS imputations_immuables();
            DROP FUNCTION IF EXISTS versements_immuables();
            DROP FUNCTION IF EXISTS compte_sans_troncature();
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
