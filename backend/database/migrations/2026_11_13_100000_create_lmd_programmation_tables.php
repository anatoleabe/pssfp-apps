<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Programmation des enseignants (spec module 4, lot 12d ; D22, sql/15) : quota horaire d'un ECUE pour
 * une période de cours, part confiée à chaque enseignant, indisponibilités ; la séance consomme sa programmation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotas_horaires', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('periode_id')->constrained('evenements_calendrier')->restrictOnDelete();
            $table->foreignId('maquette_ecue_id')->constrained('maquette_ecues')->restrictOnDelete();
            $table->decimal('heures_cm', 5, 1)->default(0);
            $table->decimal('heures_td', 5, 1)->default(0);
            $table->decimal('heures_tp', 5, 1)->default(0);
            $table->string('statut', 10)->default('propose');
            $table->string('motif_modification', 500)->nullable();
            $table->foreignId('valide_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('valide_at')->nullable();
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['periode_id', 'maquette_ecue_id']);
            $table->index('maquette_ecue_id');
            $table->index('valide_par');
            $table->index('cree_par');
        });

        Schema::create('programmations_enseignant', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('quota_id')->constrained('quotas_horaires')->cascadeOnDelete();
            $table->foreignId('enseignant_id')->constrained('users')->restrictOnDelete();
            $table->string('role', 12)->default('intervenant');
            $table->decimal('heures_cm', 5, 1)->default(0);
            $table->decimal('heures_td', 5, 1)->default(0);
            $table->decimal('heures_tp', 5, 1)->default(0);
            $table->date('debut_prevu')->nullable();
            $table->date('fin_prevue')->nullable();
            $table->string('modalite', 12)->default('hybride');
            $table->string('statut', 10)->default('propose');
            $table->string('motif', 500)->nullable();
            $table->foreignId('valide_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('valide_at')->nullable();
            $table->timestampTz('notifie_at')->nullable();
            $table->timestampTz('repondu_at')->nullable();
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index('quota_id');
            $table->index(['enseignant_id', 'statut']);
            $table->index('valide_par');
            $table->index('cree_par');
        });

        Schema::create('indisponibilites_enseignant', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enseignant_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('debut');
            $table->timestampTz('fin');
            $table->string('motif', 255)->nullable();
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['enseignant_id', 'debut']);
            $table->index('cree_par');
        });

        Schema::table('seances', function (Blueprint $table): void {
            $table->foreignId('programmation_id')->nullable()->constrained('programmations_enseignant')->nullOnDelete();
            $table->index('programmation_id');
        });

        DB::statement("ALTER TABLE quotas_horaires ADD CONSTRAINT quotas_horaires_check CHECK (heures_cm >= 0 AND heures_td >= 0 AND heures_tp >= 0
            AND statut IN ('propose', 'valide', 'modifie') AND (statut <> 'modifie' OR motif_modification IS NOT NULL))");
        DB::statement("ALTER TABLE programmations_enseignant ADD CONSTRAINT programmations_enseignant_check CHECK (heures_cm >= 0 AND heures_td >= 0 AND heures_tp >= 0
            AND role IN ('responsable', 'intervenant') AND modalite IN ('presentiel', 'distanciel', 'hybride')
            AND statut IN ('propose', 'valide', 'notifie', 'accepte', 'decline', 'annule')
            AND (fin_prevue IS NULL OR debut_prevu IS NULL OR fin_prevue >= debut_prevu)
            AND (statut NOT IN ('decline', 'annule') OR motif IS NOT NULL))");
        // Un enseignant n'a qu'une programmation active par quota ; un seul responsable par quota (D22).
        DB::statement("CREATE UNIQUE INDEX programmations_enseignant_unique ON programmations_enseignant (quota_id, enseignant_id) WHERE statut <> 'annule'");
        DB::statement("CREATE UNIQUE INDEX programmations_enseignant_responsable ON programmations_enseignant (quota_id) WHERE role = 'responsable' AND statut NOT IN ('annule', 'decline')");
        DB::statement('ALTER TABLE indisponibilites_enseignant ADD CONSTRAINT indisponibilites_enseignant_check CHECK (fin > debut)');
    }

    public function down(): void
    {
        Schema::table('seances', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('programmation_id');
        });
        Schema::dropIfExists('indisponibilites_enseignant');
        Schema::dropIfExists('programmations_enseignant');
        Schema::dropIfExists('quotas_horaires');
    }
};
