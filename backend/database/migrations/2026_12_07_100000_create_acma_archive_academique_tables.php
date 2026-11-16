<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 15b (spec du lot 15, §7.2 et §7.3 ; ADR-0014) : archive académique d'ACMA, distincte du modèle vivant.
 *
 * Chaque ligne d'archive porte sa clé naturelle d'ACMA, l'empreinte de sa source et, si elle a disparu d'un export
 * plus récent, la date de ce constat (jamais supprimée). SQL brut autorisé par l'ADR-0014 (décision 8) : déclencheurs
 * de lecture seule, de gel et d'ajout seul, contraintes CHECK.
 */
return new class extends Migration
{
    /** Tables protégées par la fonction générique de lecture seule. */
    private const TABLES_LECTURE_SEULE = ['acma_annees', 'acma_unites', 'acma_inscriptions', 'acma_resultats_ue', 'acma_notes_non_rattachees', 'acma_versions_notes'];

    public function up(): void
    {
        // Ne jamais attendre indéfiniment un verrou en production.
        DB::statement("SET LOCAL lock_timeout = '5s'");
        Schema::create('acma_reprises', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('export_fichier')->nullable();
            $table->string('export_date', 20)->nullable();
            $table->string('manifeste_sha256', 64);
            $table->jsonb('etapes');
            $table->string('mode', 10);
            $table->string('statut', 10);
            $table->jsonb('comptes');
            $table->jsonb('controles');
            $table->boolean('controles_ok');
            $table->foreignId('lance_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('debut_at');
            $table->timestampTz('fin_at')->nullable();
            $table->timestampTz('gelee_at')->nullable();
            $table->foreignId('gelee_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index('lance_par');
            $table->index('gelee_par');
            $table->index('statut');
        });

        Schema::create('acma_annees', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 9)->unique();
            $table->string('statut_acma', 20)->nullable();
            $table->string('empreinte_source', 64);
            $table->timestampTz('retire_de_la_source_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('acma_unites', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('id_prog_acma')->unique();
            $table->string('annee', 9);
            $table->string('niveau_acma', 10);
            $table->string('niveau', 2);
            $table->string('parcours', 10);
            $table->string('code_ue', 20);
            $table->string('intitule_fr')->nullable();
            $table->string('intitule_en')->nullable();
            $table->string('code_groupe', 20)->nullable();
            $table->string('libelle_groupe')->nullable();
            $table->smallInteger('semestre_acma')->nullable();
            $table->smallInteger('semestre')->nullable();
            $table->smallInteger('credits')->nullable();
            $table->decimal('coefficient', 8, 3)->nullable();
            $table->decimal('pcc', 6, 4)->nullable();
            $table->decimal('ptp', 6, 4)->nullable();
            $table->decimal('pexam', 6, 4)->nullable();
            $table->jsonb('heures')->nullable();
            $table->boolean('ponderations_hors_norme')->default(false);
            $table->boolean('groupe_autre_niveau')->default(false);
            $table->string('empreinte_source', 64);
            $table->timestampTz('retire_de_la_source_at')->nullable();
            $table->timestampsTz();

            $table->index(['annee', 'niveau_acma']);
        });

        Schema::create('acma_dossiers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('matricule', 20)->unique();
            $table->string('matricule_source', 20);
            $table->string('nom_complet', 100)->nullable();
            $table->string('sexe', 1)->nullable();
            $table->date('date_naissance')->nullable();
            $table->string('date_naissance_source', 50)->nullable();
            $table->string('date_naissance_precision', 10)->nullable();
            $table->smallInteger('annee_naissance')->nullable();
            $table->boolean('date_naissance_a_verifier')->default(false);
            $table->string('lieu_naissance', 100)->nullable();
            $table->string('nationalite', 50)->nullable();
            $table->string('categorie_acma', 10)->nullable();
            $table->string('filiere_acma', 10)->nullable();
            $table->string('premiere_annee', 9)->nullable();
            $table->string('derniere_annee', 9)->nullable();
            $table->foreignId('auditeur_id')->nullable()->constrained('auditeurs')->restrictOnDelete();
            $table->string('statut_rapprochement', 15)->default('sans_auditeur');
            $table->jsonb('fiches_fusionnees')->nullable();
            $table->jsonb('signalements')->nullable();
            $table->string('empreinte_source', 64);
            $table->timestampTz('retire_de_la_source_at')->nullable();
            $table->timestampsTz();

            $table->index('auditeur_id');
            $table->index('statut_rapprochement');
        });

        Schema::create('acma_inscriptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('cle_source', 60)->unique();
            $table->foreignId('dossier_id')->constrained('acma_dossiers')->restrictOnDelete();
            $table->string('annee', 9);
            $table->string('niveau_acma', 10);
            $table->string('niveau', 2);
            $table->string('parcours', 10);
            $table->jsonb('ids_acma');
            // Valeurs enregistrées par ACMA, telles quelles.
            $table->boolean('admis_acma')->nullable();
            $table->decimal('moyenne_acma', 8, 3)->nullable();
            $table->integer('total_credits_acma')->nullable();
            $table->integer('nb_dettes_acma')->nullable();
            $table->decimal('frais_scolarite_acma', 14, 2)->nullable();
            $table->boolean('redoublant_acma')->nullable();
            $table->boolean('deux_niveaux_meme_annee')->default(false);
            // Recalculs selon les règles d'ACMA (indicatifs) et décision d'archive (§7.5).
            $table->integer('credits_programme')->nullable();
            $table->integer('credits_acquis')->nullable();
            $table->decimal('moyenne_recalculee', 5, 2)->nullable();
            $table->integer('nb_rattrapages')->nullable();
            $table->integer('nb_conflits')->nullable();
            $table->string('decision', 16)->nullable();
            $table->string('decision_motif', 30)->nullable();
            $table->jsonb('signalements')->nullable();
            $table->string('empreinte_source', 64);
            $table->timestampTz('retire_de_la_source_at')->nullable();
            $table->timestampsTz();

            $table->unique(['dossier_id', 'annee', 'niveau_acma']);
            $table->index(['annee', 'niveau_acma']);
        });

        Schema::create('acma_resultats_ue', function (Blueprint $table): void {
            $table->id();
            $table->string('cle_source', 60)->unique();
            $table->foreignId('inscription_id')->constrained('acma_inscriptions')->restrictOnDelete();
            $table->foreignId('unite_id')->constrained('acma_unites')->restrictOnDelete();
            $table->string('matricule_source', 20);
            $table->unsignedBigInteger('id_note_acma')->nullable();
            $table->string('origine', 10);
            $table->double('cc_brut')->nullable();
            $table->double('tp_brut')->nullable();
            $table->double('examen_brut')->nullable();
            $table->double('rattrapage_brut')->nullable();
            $table->decimal('cc', 5, 2)->nullable();
            $table->decimal('tp', 5, 2)->nullable();
            $table->decimal('examen', 5, 2)->nullable();
            $table->decimal('rattrapage', 5, 2)->nullable();
            $table->integer('ok_acma')->nullable();
            $table->boolean('rattrapage_applique')->default(false);
            $table->string('session', 10);
            $table->decimal('moyenne', 5, 2)->nullable();
            $table->smallInteger('credits')->nullable();
            $table->boolean('acquise')->nullable();
            $table->boolean('en_conflit')->default(false);
            $table->boolean('hors_bornes')->default(false);
            $table->string('horodatage_source', 30)->nullable();
            $table->string('empreinte_source', 64);
            $table->timestampTz('retire_de_la_source_at')->nullable();
            $table->timestampsTz();

            $table->index(['inscription_id', 'unite_id']);
            $table->index('unite_id');
        });

        Schema::create('acma_notes_non_rattachees', function (Blueprint $table): void {
            $table->id();
            $table->string('cle_source', 60)->unique();
            $table->foreignId('dossier_id')->constrained('acma_dossiers')->restrictOnDelete();
            $table->unsignedBigInteger('id_prog_acma');
            $table->string('code_ue', 20)->nullable();
            $table->string('origine', 10);
            // Programme supprimé dans ACMA (année inconnue, Q6), ou aucune inscription de l'auditeur à l'année et au niveau du programme.
            $table->string('motif', 20);
            $table->unsignedBigInteger('id_note_acma')->nullable();
            $table->double('cc')->nullable();
            $table->double('tp')->nullable();
            $table->double('examen')->nullable();
            $table->double('rattrapage')->nullable();
            $table->integer('ok_acma')->nullable();
            $table->string('horodatage_source', 30)->nullable();
            $table->string('empreinte_source', 64);
            $table->timestampTz('retire_de_la_source_at')->nullable();
            $table->timestampsTz();

            $table->index('dossier_id');
        });

        Schema::create('acma_versions_notes', function (Blueprint $table): void {
            $table->id();
            $table->string('cle_source', 60)->unique();
            $table->unsignedBigInteger('id_note_acma');
            $table->timestamp('horodatage')->nullable();
            $table->string('horodatage_source', 30);
            $table->foreignId('dossier_id')->constrained('acma_dossiers')->restrictOnDelete();
            $table->unsignedBigInteger('id_prog_acma')->nullable();
            $table->foreignId('unite_id')->nullable()->constrained('acma_unites')->restrictOnDelete();
            $table->string('code_ue', 20)->nullable();
            $table->double('cc')->nullable();
            $table->double('tp')->nullable();
            $table->double('examen')->nullable();
            $table->double('rattrapage')->nullable();
            $table->integer('ok_acma')->nullable();
            $table->integer('validation_acma')->nullable();
            $table->string('empreinte_source', 64);
            $table->timestampTz('retire_de_la_source_at')->nullable();
            $table->timestampsTz();

            $table->index('id_note_acma');
            $table->index('dossier_id');
            $table->index('unite_id');
        });

        Schema::create('acma_rapprochements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dossier_id')->constrained('acma_dossiers')->restrictOnDelete();
            $table->foreignId('auditeur_id')->nullable()->constrained('auditeurs')->restrictOnDelete();
            $table->string('cas', 2);
            $table->decimal('score', 4, 3)->nullable();
            $table->string('decision', 10)->nullable();
            $table->string('motif', 500);
            $table->foreignId('decide_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('reprise_id')->nullable()->constrained('acma_reprises')->restrictOnDelete();
            $table->timestampTz('created_at');

            $table->index('dossier_id');
            $table->index('auditeur_id');
            $table->index('decide_par');
            $table->index('reprise_id');
        });

        $this->contraintes();
        $this->declencheurs();
    }

    public function down(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        if (Schema::hasTable('acma_reprises') && DB::table('acma_reprises')->where('statut', 'gelee')->exists()) {
            throw new RuntimeException('Archive ACMA gelée : défaire ce lot demande une migration motivée (spec du lot 15, §7.3).');
        }
        if (Schema::hasTable('acma_rapprochements') && DB::table('acma_rapprochements')->whereNotNull('decide_par')->exists()) {
            throw new RuntimeException('Arbitrages ACMA enregistrés : défaire ce lot les perdrait ; il demande une migration motivée.');
        }
        foreach (array_merge(self::TABLES_LECTURE_SEULE, ['acma_dossiers']) as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_lecture_seule ON {$table}");
            DB::statement("DROP TRIGGER IF EXISTS {$table}_sans_truncate ON {$table}");
        }
        DB::statement('DROP TRIGGER IF EXISTS acma_rapprochements_ajout_seul ON acma_rapprochements');
        DB::statement('DROP TRIGGER IF EXISTS acma_rapprochements_sans_truncate ON acma_rapprochements');
        DB::statement('DROP TRIGGER IF EXISTS acma_reprises_protection ON acma_reprises');
        DB::statement('DROP TRIGGER IF EXISTS acma_reprises_sans_truncate ON acma_reprises');

        Schema::dropIfExists('acma_rapprochements');
        Schema::dropIfExists('acma_versions_notes');
        Schema::dropIfExists('acma_notes_non_rattachees');
        Schema::dropIfExists('acma_resultats_ue');
        Schema::dropIfExists('acma_inscriptions');
        Schema::dropIfExists('acma_dossiers');
        Schema::dropIfExists('acma_unites');
        Schema::dropIfExists('acma_annees');
        Schema::dropIfExists('acma_reprises');

        foreach (['acma_archive_lecture_seule()', 'acma_dossiers_lecture_seule()', 'acma_rapprochements_ajout_seul()', 'acma_reprises_protection()', 'acma_mode_reprise()', 'acma_archive_gelee()'] as $fonction) {
            DB::statement("DROP FUNCTION IF EXISTS {$fonction}");
        }
    }

    private function contraintes(): void
    {
        $annee = "~ '^[0-9]{4}-[0-9]{4}$'";
        $note = fn (string $c): string => "({$c} IS NULL OR {$c} BETWEEN 0 AND 20)";
        $checks = [
            'acma_reprises' => [
                'mode' => "mode IN ('essai', 'appliquee')",
                'statut' => "statut IN ('a_blanc', 'refusee', 'appliquee', 'gelee')",
                'gel' => "(statut = 'gelee') = (gelee_at IS NOT NULL)",
            ],
            'acma_annees' => ['code' => "code {$annee}"],
            'acma_unites' => [
                'annee' => "annee {$annee}",
                'niveau' => "niveau IN ('M1', 'M2')",
                'semestre' => 'semestre IS NULL OR semestre BETWEEN 1 AND 4',
            ],
            'acma_dossiers' => [
                'statut' => "statut_rapprochement IN ('lie_auto', 'lie_arbitre', 'sans_auditeur', 'a_arbitrer', 'ecarte')",
                'lien' => "(statut_rapprochement IN ('lie_auto', 'lie_arbitre')) = (auditeur_id IS NOT NULL)",
                'sexe' => "sexe IS NULL OR sexe IN ('M', 'F')",
                'precision' => "date_naissance_precision IS NULL OR date_naissance_precision = 'vers'",
                'annees' => "(premiere_annee IS NULL OR premiere_annee {$annee}) AND (derniere_annee IS NULL OR derniere_annee {$annee})",
            ],
            'acma_inscriptions' => [
                'annee' => "annee {$annee}",
                'niveau' => "niveau IN ('M1', 'M2')",
                'decision' => "decision IS NULL OR decision IN ('admis', 'non_admis', 'non_enregistree')",
                'motif' => "decision_motif IS NULL OR decision_motif IN ('cohorte_sans_decision', 'sans_note', 'admis_absent')",
            ],
            'acma_resultats_ue' => [
                'origine' => "origine IN ('notes', 'historique')",
                'session' => "session IN ('normale', 'rattrapage')",
                'notes' => implode(' AND ', array_map($note, ['cc', 'tp', 'examen', 'rattrapage', 'moyenne'])),
            ],
            'acma_notes_non_rattachees' => [
                'origine' => "origine IN ('notes', 'historique')",
                'motif' => "motif IN ('programme_disparu', 'sans_inscription')",
            ],
            'acma_rapprochements' => [
                'cas' => "cas IN ('R1', 'R2', 'R3', 'R4', 'R5', 'R6')",
                'decision' => "decision IS NULL OR decision IN ('lie', 'non_lie', 'ecarte')",
                'score' => 'score IS NULL OR score BETWEEN 0 AND 1',
                'arbitrage' => 'decide_par IS NULL OR (decision IS NOT NULL AND char_length(motif) >= 10)',
                'lien' => "decision IS DISTINCT FROM 'lie' OR auditeur_id IS NOT NULL",
            ],
        ];
        foreach ($checks as $table => $contraintes) {
            foreach ($contraintes as $nom => $expression) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$nom}_check CHECK ({$expression})");
            }
        }
    }

    private function declencheurs(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION acma_archive_gelee() RETURNS boolean LANGUAGE sql STABLE AS $$
              SELECT EXISTS (SELECT 1 FROM acma_reprises WHERE statut = 'gelee')
            $$;

            CREATE OR REPLACE FUNCTION acma_mode_reprise() RETURNS boolean LANGUAGE sql STABLE AS $$
              SELECT coalesce(current_setting('app.reprise_acma', true), '') = 'on'
            $$;

            CREATE OR REPLACE FUNCTION acma_archive_lecture_seule() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF acma_archive_gelee() THEN
                RAISE EXCEPTION 'Archive ACMA gelée : aucune écriture' USING ERRCODE = 'check_violation';
              END IF;
              IF NOT acma_mode_reprise() THEN
                RAISE EXCEPTION 'Archive ACMA en lecture seule : écriture réservée à acma:reprendre' USING ERRCODE = 'check_violation';
              END IF;
              RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END $$;

            -- Le lien d'un dossier vers un auditeur échappe au gel : seul l'arbitrage le change, et la base exige que la
            -- dernière décision signée du dossier (acma_rapprochements, ajout seul) corresponde exactement au nouveau lien.
            CREATE OR REPLACE FUNCTION acma_dossiers_lecture_seule() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
              d record;
            BEGIN
              IF acma_mode_reprise() AND NOT acma_archive_gelee() THEN
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
              END IF;
              IF TG_OP = 'UPDATE'
                 AND (to_jsonb(NEW) - ARRAY['auditeur_id', 'statut_rapprochement', 'updated_at'])
                   = (to_jsonb(OLD) - ARRAY['auditeur_id', 'statut_rapprochement', 'updated_at']) THEN
                SELECT r.decision, r.auditeur_id INTO d FROM acma_rapprochements r
                  WHERE r.dossier_id = NEW.id AND r.decide_par IS NOT NULL ORDER BY r.id DESC LIMIT 1;
                IF FOUND AND (
                     (d.decision = 'lie' AND NEW.statut_rapprochement = 'lie_arbitre' AND NEW.auditeur_id IS NOT DISTINCT FROM d.auditeur_id)
                  OR (d.decision = 'non_lie' AND NEW.statut_rapprochement = 'sans_auditeur' AND NEW.auditeur_id IS NULL)
                  OR (d.decision = 'ecarte' AND NEW.statut_rapprochement = 'ecarte' AND NEW.auditeur_id IS NULL)) THEN
                  RETURN NEW;
                END IF;
                RAISE EXCEPTION 'Lien d''un dossier ACMA : arbitrage enregistré exigé' USING ERRCODE = 'check_violation';
              END IF;
              IF acma_archive_gelee() THEN
                RAISE EXCEPTION 'Archive ACMA gelée : aucune écriture' USING ERRCODE = 'check_violation';
              END IF;
              RAISE EXCEPTION 'Archive ACMA en lecture seule : écriture réservée à acma:reprendre' USING ERRCODE = 'check_violation';
            END $$;

            CREATE OR REPLACE FUNCTION acma_rapprochements_ajout_seul() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'Rapprochements ACMA en ajout seul' USING ERRCODE = 'check_violation';
            END $$;

            -- Une reprise ne se supprime jamais ; une reprise gelée ne change plus ; le gel est irréversible par l'application.
            CREATE OR REPLACE FUNCTION acma_reprises_protection() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP IN ('DELETE', 'TRUNCATE') THEN
                RAISE EXCEPTION 'Reprises ACMA : suppression interdite' USING ERRCODE = 'check_violation';
              END IF;
              IF NOT acma_mode_reprise() THEN
                RAISE EXCEPTION 'Reprises ACMA : écriture réservée aux commandes de reprise' USING ERRCODE = 'check_violation';
              END IF;
              -- Archive déjà gelée : plus aucune modification d'aucune reprise. La transition de gel elle-même passe,
              -- puisqu'aucune reprise n'est encore gelée à l'instant où elle s'écrit.
              IF TG_OP = 'UPDATE' AND (OLD.statut = 'gelee' OR acma_archive_gelee()) THEN
                RAISE EXCEPTION 'Archive ACMA gelée : aucune écriture' USING ERRCODE = 'check_violation';
              END IF;
              IF TG_OP = 'INSERT' AND acma_archive_gelee() THEN
                RAISE EXCEPTION 'Archive ACMA gelée : aucune écriture' USING ERRCODE = 'check_violation';
              END IF;
              RETURN NEW;
            END $$;
        SQL);

        foreach (self::TABLES_LECTURE_SEULE as $table) {
            DB::statement("CREATE TRIGGER {$table}_lecture_seule BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION acma_archive_lecture_seule()");
            DB::statement("CREATE TRIGGER {$table}_sans_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION acma_archive_lecture_seule()");
        }
        DB::statement('CREATE TRIGGER acma_dossiers_lecture_seule BEFORE INSERT OR UPDATE OR DELETE ON acma_dossiers FOR EACH ROW EXECUTE FUNCTION acma_dossiers_lecture_seule()');
        DB::statement('CREATE TRIGGER acma_dossiers_sans_truncate BEFORE TRUNCATE ON acma_dossiers FOR EACH STATEMENT EXECUTE FUNCTION acma_archive_lecture_seule()');
        DB::statement('CREATE TRIGGER acma_rapprochements_ajout_seul BEFORE UPDATE OR DELETE ON acma_rapprochements FOR EACH ROW EXECUTE FUNCTION acma_rapprochements_ajout_seul()');
        DB::statement('CREATE TRIGGER acma_rapprochements_sans_truncate BEFORE TRUNCATE ON acma_rapprochements FOR EACH STATEMENT EXECUTE FUNCTION acma_rapprochements_ajout_seul()');
        DB::statement('CREATE TRIGGER acma_reprises_protection BEFORE INSERT OR UPDATE OR DELETE ON acma_reprises FOR EACH ROW EXECUTE FUNCTION acma_reprises_protection()');
        DB::statement('CREATE TRIGGER acma_reprises_sans_truncate BEFORE TRUNCATE ON acma_reprises FOR EACH STATEMENT EXECUTE FUNCTION acma_reprises_protection()');
    }
};
