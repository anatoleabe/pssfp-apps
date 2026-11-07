<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Délibérations (spec module 4, lot 6 ; ADR-0011 ; D2, D4, D14, D15, D17, D20, D35, D37).
 *
 * - La note saisie n'est jamais modifiée par le jury : une mesure produit une note distincte.
 * - À l'arrêt, les résultats sont figés (ajout seul, garanti par la base) et les acquis écrits.
 *
 * Strictement additif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jurys', function (Blueprint $table): void {
            $table->id();
            $table->string('annee_academique', 9);
            $table->string('niveau', 2);
            $table->foreignId('parcours_id')->nullable()->constrained('parcours')->restrictOnDelete();
            $table->string('nature', 30);
            $table->string('objet', 500);
            $table->string('acte_reference', 120);
            $table->date('acte_date');
            $table->string('acte_signataire', 255);
            $table->string('acte_piece_path')->nullable();
            $table->timestampTz('seance_le')->nullable();
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index('parcours_id');
            $table->index('cree_par');
        });

        Schema::create('membres_jury', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('jury_id')->constrained('jurys')->restrictOnDelete();
            $table->string('role', 20);
            $table->unsignedSmallInteger('rang')->default(1);
            $table->string('titre', 20)->nullable();
            $table->string('nom', 255);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['jury_id', 'nom']);
            $table->index('user_id');
        });

        Schema::create('deliberations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('annee_academique', 9);
            $table->string('niveau', 2);
            $table->foreignId('parcours_id')->nullable()->constrained('parcours')->restrictOnDelete();
            $table->string('session', 12);
            $table->foreignId('jury_id')->nullable()->constrained('jurys')->restrictOnDelete();
            $table->date('date_deliberation')->nullable();
            $table->string('statut', 12)->default('simulation');
            $table->timestampTz('seance_ouverte_at')->nullable();
            $table->boolean('quorum_atteint')->nullable();
            $table->string('decision_jury', 20)->nullable();
            $table->string('numero_pv', 30)->nullable()->unique();
            $table->char('pv_sha256', 64)->nullable();
            $table->char('pv_pdf_sha256', 64)->nullable();
            $table->foreignId('arretee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('arretee_at')->nullable();
            $table->foreignId('publiee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('publiee_at')->nullable();
            $table->date('requetes_jusqu_au')->nullable();
            $table->foreignId('rectifie_id')->nullable()->constrained('deliberations')->restrictOnDelete();
            $table->string('motif_rectification', 500)->nullable();
            $table->string('acte_rectificatif', 120)->nullable();
            $table->foreignId('creee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['annee_academique', 'niveau']);
            $table->index('jury_id');
            $table->index('parcours_id');
            $table->index('rectifie_id');
            $table->index('arretee_par');
            $table->index('publiee_par');
            $table->index('creee_par');
        });

        Schema::create('participations_deliberation', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deliberation_id')->constrained('deliberations')->restrictOnDelete();
            $table->foreignId('membre_jury_id')->constrained('membres_jury')->restrictOnDelete();
            $table->boolean('present');
            $table->string('motif_absence', 255)->nullable();
            $table->timestampTz('signe_at')->nullable();
            $table->timestampsTz();

            $table->unique(['deliberation_id', 'membre_jury_id']);
            $table->index('membre_jury_id');
        });

        Schema::create('scenarios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deliberation_id')->constrained('deliberations')->restrictOnDelete();
            $table->string('libelle', 120);
            $table->boolean('retenu')->default(false);
            $table->timestampTz('calcule_at')->nullable();
            $table->unsignedInteger('effectif')->nullable();
            $table->unsignedInteger('admis')->nullable();
            $table->unsignedInteger('autorises')->nullable();
            $table->unsignedInteger('ajournes')->nullable();
            $table->decimal('taux_reussite', 5, 2)->nullable();
            $table->timestampsTz();

            $table->index('deliberation_id');
        });

        Schema::create('mesures_jury', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scenario_id')->constrained('scenarios')->restrictOnDelete();
            $table->string('type', 20);
            $table->jsonb('parametres');
            $table->string('motif', 500);
            $table->unsignedSmallInteger('ordre')->default(1);
            $table->unsignedInteger('beneficiaires')->nullable();
            $table->timestampsTz();

            $table->index('scenario_id');
        });

        Schema::create('resultats_scenario_ue', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scenario_id')->constrained('scenarios')->cascadeOnDelete();
            $table->foreignId('inscription_annuelle_id')->constrained('inscriptions_annuelles')->restrictOnDelete();
            $table->foreignId('maquette_ue_id')->constrained('maquette_ues')->restrictOnDelete();
            $table->unsignedSmallInteger('credits');
            $table->string('partie', 12);
            $table->decimal('note_normale', 6, 3)->nullable();
            $table->decimal('note_retenue', 6, 3)->nullable();
            $table->boolean('eliminatoire')->default(false);
            $table->boolean('capitalisee')->default(false);
            $table->boolean('acquise_regle')->default(false);
            $table->decimal('note_jury', 6, 3)->nullable();
            $table->boolean('acquise')->default(false);
            $table->foreignId('mesure_id')->nullable()->constrained('mesures_jury')->nullOnDelete();

            $table->unique(['scenario_id', 'inscription_annuelle_id', 'maquette_ue_id']);
            $table->index('inscription_annuelle_id');
            $table->index('maquette_ue_id');
            $table->index('mesure_id');
        });

        Schema::create('resultats_scenario', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scenario_id')->constrained('scenarios')->cascadeOnDelete();
            $table->foreignId('inscription_annuelle_id')->constrained('inscriptions_annuelles')->restrictOnDelete();
            $table->unsignedSmallInteger('credits_maquette');
            $table->unsignedSmallInteger('credits_regle');
            $table->unsignedSmallInteger('credits_acquis');
            $table->unsignedSmallInteger('credits_annee');
            $table->decimal('moyenne', 6, 3)->nullable();
            $table->decimal('mgp', 4, 2)->nullable();
            $table->string('decision_calculee', 2);
            $table->string('decision', 2);
            $table->unsignedInteger('rang')->nullable();
            $table->string('mention', 80)->nullable();
            $table->boolean('felicitations_proposees')->default(false);
            $table->string('mesures', 1000)->nullable();

            $table->unique(['scenario_id', 'inscription_annuelle_id']);
            $table->index('inscription_annuelle_id');
        });

        Schema::create('resultats_annuels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deliberation_id')->constrained('deliberations')->restrictOnDelete();
            $table->foreignId('inscription_annuelle_id')->constrained('inscriptions_annuelles')->restrictOnDelete();
            $table->unsignedSmallInteger('credits_acquis');
            $table->unsignedSmallInteger('credits_annee');
            $table->decimal('moyenne', 6, 3)->nullable();
            $table->decimal('mgp', 4, 2)->nullable();
            $table->string('decision_calculee', 2);
            $table->string('decision', 2);
            $table->unsignedInteger('rang')->nullable();
            $table->string('mention', 80)->nullable();
            $table->boolean('felicitations_proposees')->default(false);
            $table->string('mesures', 1000)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['deliberation_id', 'inscription_annuelle_id']);
            $table->index('inscription_annuelle_id');
        });

        Schema::create('acquis_ue', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('auditeur_id')->constrained('auditeurs')->restrictOnDelete();
            $table->foreignId('unite_enseignement_id')->constrained('unites_enseignement')->restrictOnDelete();
            $table->foreignId('maquette_ue_id')->constrained('maquette_ues')->restrictOnDelete();
            $table->string('niveau', 2);
            $table->unsignedSmallInteger('credits');
            $table->decimal('note_retenue', 6, 3)->nullable();
            $table->string('annee_academique', 9);
            $table->string('session', 12);
            $table->string('origine', 20);
            $table->foreignId('deliberation_id')->nullable()->constrained('deliberations')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['auditeur_id', 'maquette_ue_id']);
            $table->index(['auditeur_id', 'unite_enseignement_id']);
            $table->index('unite_enseignement_id');
            $table->index('maquette_ue_id');
            $table->index('deliberation_id');
        });

        // SQL brut, ADR-0011 : états fermés, un président et un rapporteur, résultats figés.
        DB::statement("ALTER TABLE jurys ADD CONSTRAINT jurys_valeurs_check CHECK (niveau IN ('M1', 'M2') AND nature IN ('deliberation', 'rattrapage', 'synthese_memoires', 'requetes', 'special'))");
        DB::statement("ALTER TABLE membres_jury ADD CONSTRAINT membres_jury_role_check CHECK (role IN ('president', 'vice_president', 'rapporteur', 'membre'))");
        DB::statement("CREATE UNIQUE INDEX membres_jury_president_unique ON membres_jury (jury_id) WHERE role = 'president'");
        DB::statement("CREATE UNIQUE INDEX membres_jury_rapporteur_unique ON membres_jury (jury_id) WHERE role = 'rapporteur'");
        DB::statement("ALTER TABLE deliberations ADD CONSTRAINT deliberations_valeurs_check CHECK (niveau IN ('M1', 'M2') AND session IN ('normale', 'rattrapage') AND statut IN ('simulation', 'arretee', 'rectifiee') AND (decision_jury IS NULL OR decision_jury IN ('deliberation', 'sans_deliberation')))");
        DB::statement("ALTER TABLE deliberations ADD CONSTRAINT deliberations_arret_check CHECK (statut = 'simulation' OR (numero_pv IS NOT NULL AND pv_sha256 IS NOT NULL AND arretee_at IS NOT NULL))");
        DB::statement('ALTER TABLE deliberations ADD CONSTRAINT deliberations_publication_check CHECK (publiee_at IS NULL OR statut <> \'simulation\')');
        DB::statement('ALTER TABLE deliberations ADD CONSTRAINT deliberations_rectification_check CHECK (rectifie_id IS NULL OR (motif_rectification IS NOT NULL AND acte_rectificatif IS NOT NULL))');
        DB::statement('ALTER TABLE participations_deliberation ADD CONSTRAINT participations_signature_check CHECK (signe_at IS NULL OR present)');
        DB::statement('CREATE UNIQUE INDEX scenarios_retenu_unique ON scenarios (deliberation_id) WHERE retenu');
        DB::statement("ALTER TABLE mesures_jury ADD CONSTRAINT mesures_jury_type_check CHECK (type IN ('relevement', 'repechage', 'cas_particulier'))");
        DB::statement("ALTER TABLE resultats_scenario ADD CONSTRAINT resultats_scenario_decision_check CHECK (decision IN ('AD', 'AS', 'AJ') AND decision_calculee IN ('AD', 'AS', 'AJ'))");
        DB::statement("ALTER TABLE resultats_annuels ADD CONSTRAINT resultats_annuels_decision_check CHECK (decision IN ('AD', 'AS', 'AJ') AND decision_calculee IN ('AD', 'AS', 'AJ'))");
        DB::statement("ALTER TABLE acquis_ue ADD CONSTRAINT acquis_ue_valeurs_check CHECK (niveau IN ('M1', 'M2') AND session IN ('normale', 'rattrapage') AND origine IN ('evaluation', 'deliberation', 'reprise', 'correspondance'))");
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION resultats_annuels_figes() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'Les résultats arrêtés sont figés : rectification de la délibération obligatoire' USING ERRCODE = 'check_violation';
            END $$
            SQL);
        DB::statement('CREATE TRIGGER resultats_annuels_figes BEFORE UPDATE OR DELETE ON resultats_annuels FOR EACH ROW EXECUTE FUNCTION resultats_annuels_figes()');
    }

    public function down(): void
    {
        Schema::dropIfExists('acquis_ue');
        DB::statement('DROP TRIGGER IF EXISTS resultats_annuels_figes ON resultats_annuels');
        Schema::dropIfExists('resultats_annuels');
        DB::statement('DROP FUNCTION IF EXISTS resultats_annuels_figes()');
        Schema::dropIfExists('resultats_scenario');
        Schema::dropIfExists('resultats_scenario_ue');
        Schema::dropIfExists('mesures_jury');
        Schema::dropIfExists('scenarios');
        Schema::dropIfExists('participations_deliberation');
        Schema::dropIfExists('deliberations');
        Schema::dropIfExists('membres_jury');
        Schema::dropIfExists('jurys');
    }
};
