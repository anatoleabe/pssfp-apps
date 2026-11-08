<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Emplois du temps (spec module 4, lot 12c ; D21). Additif : les séances du lot 10 gagnent leur
 * emploi du temps, leur salle, leur plage, leur enseignant, leur nature et le report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salles', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('libelle', 255);
            $table->unsignedSmallInteger('capacite')->nullable();
            $table->string('nature', 12)->default('presentiel');
            $table->string('lien_reunion', 500)->nullable();
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('plages_horaires', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->time('heure_debut');
            $table->time('heure_fin');
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestampsTz();
        });

        Schema::create('emplois_du_temps', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('periode_id')->constrained('evenements_calendrier')->restrictOnDelete();
            $table->foreignId('parcours_id')->constrained('parcours')->restrictOnDelete();
            $table->string('statut', 10)->default('brouillon');
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('publie_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('publie_at')->nullable();
            $table->timestampsTz();

            $table->unique(['periode_id', 'parcours_id']);
            $table->index('parcours_id');
            $table->index('cree_par');
            $table->index('publie_par');
        });

        Schema::table('seances', function (Blueprint $table): void {
            $table->foreignId('emploi_du_temps_id')->nullable()->after('maquette_ecue_id')->constrained('emplois_du_temps')->restrictOnDelete();
            $table->foreignId('salle_id')->nullable()->after('salle')->constrained('salles')->restrictOnDelete();
            $table->foreignId('plage_horaire_id')->nullable()->after('fin')->constrained('plages_horaires')->restrictOnDelete();
            $table->foreignId('enseignant_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nature', 12)->nullable();
            $table->foreignId('reportee_vers_id')->nullable()->constrained('seances')->nullOnDelete();
            $table->string('motif_report', 500)->nullable();

            $table->index('emploi_du_temps_id');
            $table->index('salle_id');
            $table->index('plage_horaire_id');
            $table->index(['enseignant_id', 'date_seance']);
            $table->index('reportee_vers_id');
            $table->index('date_seance');
        });

        DB::statement("ALTER TABLE salles ADD CONSTRAINT salles_check CHECK (nature IN ('presentiel', 'virtuelle') AND (capacite IS NULL OR capacite > 0))");
        DB::statement('ALTER TABLE plages_horaires ADD CONSTRAINT plages_horaires_check CHECK (heure_fin > heure_debut)');
        DB::statement("ALTER TABLE emplois_du_temps ADD CONSTRAINT emplois_du_temps_check CHECK (statut IN ('brouillon', 'publie'))");
        DB::statement('ALTER TABLE seances DROP CONSTRAINT seances_valeurs_check');
        DB::statement("ALTER TABLE seances ADD CONSTRAINT seances_valeurs_check CHECK (modalite IN ('presentiel', 'distanciel', 'hybride') AND statut IN ('prevue', 'tenue', 'annulee', 'reportee') AND fin > debut AND (nature IS NULL OR nature IN ('CM', 'TD', 'TP', 'evaluation')))");
        DB::statement("ALTER TABLE seances ADD CONSTRAINT seances_report_check CHECK (statut <> 'reportee' OR motif_report IS NOT NULL)");

        $maintenant = now();
        DB::table('plages_horaires')->insert([
            ['code' => 'MATIN', 'heure_debut' => '08:00', 'heure_fin' => '11:00', 'ordre' => 1, 'created_at' => $maintenant, 'updated_at' => $maintenant],
            ['code' => 'MIDI', 'heure_debut' => '11:30', 'heure_fin' => '14:30', 'ordre' => 2, 'created_at' => $maintenant, 'updated_at' => $maintenant],
            ['code' => 'APRES_MIDI', 'heure_debut' => '15:00', 'heure_fin' => '18:00', 'ordre' => 3, 'created_at' => $maintenant, 'updated_at' => $maintenant],
        ]);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE seances DROP CONSTRAINT IF EXISTS seances_report_check');
        DB::statement('ALTER TABLE seances DROP CONSTRAINT seances_valeurs_check');
        DB::table('seances')->where('statut', 'reportee')->update(['statut' => 'annulee', 'motif_annulation' => DB::raw("coalesce(motif_annulation, motif_report, 'Séance reportée')")]);
        DB::statement("ALTER TABLE seances ADD CONSTRAINT seances_valeurs_check CHECK (modalite IN ('presentiel', 'distanciel', 'hybride') AND statut IN ('prevue', 'tenue', 'annulee') AND fin > debut)");
        Schema::table('seances', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('emploi_du_temps_id');
            $table->dropConstrainedForeignId('salle_id');
            $table->dropConstrainedForeignId('plage_horaire_id');
            $table->dropIndex(['enseignant_id', 'date_seance']);
            $table->dropConstrainedForeignId('enseignant_id');
            $table->dropConstrainedForeignId('reportee_vers_id');
            $table->dropIndex(['date_seance']);
            $table->dropColumn(['nature', 'motif_report']);
        });
        Schema::dropIfExists('emplois_du_temps');
        Schema::dropIfExists('plages_horaires');
        Schema::dropIfExists('salles');
    }
};
