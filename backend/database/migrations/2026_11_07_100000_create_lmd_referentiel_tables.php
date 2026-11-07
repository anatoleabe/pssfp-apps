<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Référentiel pédagogique LMD (spec module 4, lot 4 ; ADR-0011).
 *
 * - Une maquette par année académique et par parcours ; validée, elle ne bouge plus.
 * - Le catalogue des UE est partagé entre maquettes (une UE commune n'existe qu'une fois).
 * - Les règles d'évaluation sont propres à une année : les années délibérées gardent les leurs.
 *
 * Strictement additif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parcours', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('libelle', 255);
            $table->string('niveau', 2);
            $table->string('specialite', 255)->nullable();
            $table->boolean('actif')->default(true);
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestampsTz();
        });

        Schema::create('unites_enseignement', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('intitule', 255);
            $table->timestampsTz();
        });

        Schema::create('maquettes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('annee_academique', 9);
            $table->foreignId('parcours_id')->constrained('parcours')->restrictOnDelete();
            $table->string('statut', 12)->default('brouillon');
            $table->foreignId('validee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('validee_at')->nullable();
            $table->string('source', 500)->nullable();
            $table->timestampsTz();

            $table->unique(['annee_academique', 'parcours_id']);
            $table->index('validee_par');
        });

        Schema::create('maquette_ues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('maquette_id')->constrained('maquettes')->restrictOnDelete();
            $table->foreignId('unite_enseignement_id')->constrained('unites_enseignement')->restrictOnDelete();
            $table->unsignedSmallInteger('semestre');
            $table->unsignedSmallInteger('credits');
            $table->decimal('pcc', 5, 4);
            $table->decimal('ptp', 5, 4);
            $table->decimal('pexam', 5, 4);
            $table->string('partie', 12)->default('ecrite');
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestampsTz();

            $table->unique(['maquette_id', 'unite_enseignement_id']);
            $table->index('unite_enseignement_id');
        });

        Schema::create('maquette_ecues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('maquette_ue_id')->constrained('maquette_ues')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('intitule', 500);
            $table->unsignedSmallInteger('credits');
            $table->unsignedSmallInteger('heures_cm')->default(0);
            $table->unsignedSmallInteger('heures_td')->default(0);
            $table->unsignedSmallInteger('heures_tp')->default(0);
            $table->unsignedSmallInteger('heures_tpe')->default(0);
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestampsTz();

            $table->unique(['maquette_ue_id', 'code']);
        });

        Schema::create('regles_evaluation', function (Blueprint $table): void {
            $table->id();
            $table->string('annee_academique', 9)->unique();
            $table->decimal('seuil_validation', 5, 2);
            $table->decimal('note_plancher', 5, 2)->nullable();
            $table->string('note_plancher_porte', 20);
            $table->string('mode_rattrapage', 20);
            $table->string('note_ue', 30);
            $table->decimal('pcc_defaut', 5, 4);
            $table->decimal('ptp_defaut', 5, 4);
            $table->decimal('pexam_defaut', 5, 4);
            $table->unsignedSmallInteger('credits_niveau');
            $table->unsignedSmallInteger('credits_ecrits_terminal');
            $table->jsonb('grille_grades');
            $table->jsonb('grille_mentions');
            $table->jsonb('jury');
            $table->timestampsTz();
        });

        Schema::table('inscriptions_annuelles', function (Blueprint $table): void {
            $table->foreignId('parcours_id')->nullable()->after('niveau')->constrained('parcours')->restrictOnDelete();
            $table->index('parcours_id');
        });

        Schema::create('inscriptions_pedagogiques', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inscription_annuelle_id')->constrained('inscriptions_annuelles')->restrictOnDelete();
            $table->foreignId('maquette_ue_id')->constrained('maquette_ues')->restrictOnDelete();
            $table->string('statut', 12)->default('inscrit');
            $table->string('motif', 500)->nullable();
            $table->timestampsTz();

            $table->unique(['inscription_annuelle_id', 'maquette_ue_id']);
            $table->index('maquette_ue_id');
        });

        // Contraintes que seule la base garantit, même hors des services (import, console). SQL brut, ADR-0011.
        DB::statement("ALTER TABLE parcours ADD CONSTRAINT parcours_niveau_check CHECK (niveau IN ('M1', 'M2'))");
        DB::statement("ALTER TABLE maquettes ADD CONSTRAINT maquettes_statut_check CHECK (statut IN ('brouillon', 'validee', 'archivee'))");
        DB::statement("ALTER TABLE maquettes ADD CONSTRAINT maquettes_validation_check CHECK (statut = 'brouillon' OR validee_at IS NOT NULL)");
        DB::statement("ALTER TABLE maquette_ues ADD CONSTRAINT maquette_ues_partie_check CHECK (partie IN ('ecrite', 'soutenance'))");
        DB::statement('ALTER TABLE maquette_ues ADD CONSTRAINT maquette_ues_semestre_check CHECK (semestre BETWEEN 1 AND 12)');
        DB::statement('ALTER TABLE maquette_ues ADD CONSTRAINT maquette_ues_ponderations_check CHECK (pcc >= 0 AND ptp >= 0 AND pexam >= 0 AND abs(pcc + ptp + pexam - 1) < 0.0001)');
        DB::statement('ALTER TABLE maquette_ues ADD CONSTRAINT maquette_ues_credits_check CHECK (credits >= 0)');
        DB::statement('ALTER TABLE maquette_ecues ADD CONSTRAINT maquette_ecues_valeurs_check CHECK (credits >= 0 AND heures_cm >= 0 AND heures_td >= 0 AND heures_tp >= 0 AND heures_tpe >= 0)');
        DB::statement('ALTER TABLE regles_evaluation ADD CONSTRAINT regles_evaluation_notes_check CHECK (seuil_validation BETWEEN 0 AND 20 AND (note_plancher IS NULL OR note_plancher BETWEEN 0 AND 20))');
        DB::statement("ALTER TABLE regles_evaluation ADD CONSTRAINT regles_evaluation_modes_check CHECK (note_plancher_porte IN ('examen_ue', 'examen_ecue') AND mode_rattrapage IN ('remplace_examen', 'remplace_moyenne', 'meilleure_note') AND note_ue IN ('moyenne_ecue_credits'))");
        DB::statement('ALTER TABLE regles_evaluation ADD CONSTRAINT regles_evaluation_ponderations_check CHECK (pcc_defaut >= 0 AND ptp_defaut >= 0 AND pexam_defaut >= 0 AND abs(pcc_defaut + ptp_defaut + pexam_defaut - 1) < 0.0001)');
        DB::statement('ALTER TABLE regles_evaluation ADD CONSTRAINT regles_evaluation_credits_check CHECK (credits_ecrits_terminal <= credits_niveau)');
        DB::statement("ALTER TABLE inscriptions_pedagogiques ADD CONSTRAINT inscriptions_pedagogiques_statut_check CHECK (statut IN ('inscrit', 'dispense', 'capitalisee'))");
        DB::statement("ALTER TABLE inscriptions_pedagogiques ADD CONSTRAINT inscriptions_pedagogiques_motif_check CHECK (statut = 'inscrit' OR motif IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('inscriptions_pedagogiques');
        Schema::table('inscriptions_annuelles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parcours_id');
        });
        Schema::dropIfExists('regles_evaluation');
        Schema::dropIfExists('maquette_ecues');
        Schema::dropIfExists('maquette_ues');
        Schema::dropIfExists('maquettes');
        Schema::dropIfExists('unites_enseignement');
        Schema::dropIfExists('parcours');
    }
};
