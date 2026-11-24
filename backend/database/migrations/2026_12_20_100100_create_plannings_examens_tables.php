<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Planning d'examens (spec module 4, lot 27). Additif : un planning par session d'examens du calendrier,
 * ses épreuves (un code d'ECUE, une date, des heures), leurs salles ordonnées et leurs surveillants.
 * Candidats et répartition en salles ne sont pas stockés : ils se calculent à la lecture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plannings_examens', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('session_id')->unique()->constrained('evenements_calendrier')->restrictOnDelete();
            $table->string('statut', 10)->default('brouillon');
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('publie_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('publie_at')->nullable();
            $table->timestampsTz();

            $table->index('cree_par');
            $table->index('publie_par');
        });

        Schema::create('epreuves_examen', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('planning_examens_id')->constrained('plannings_examens')->cascadeOnDelete();
            $table->string('code_ecue', 30);
            $table->string('intitule', 255);
            $table->date('date_epreuve');
            $table->time('debut');
            $table->time('fin');
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['planning_examens_id', 'code_ecue']);
            $table->index('date_epreuve');
            $table->index('cree_par');
        });

        Schema::create('epreuve_examen_salle', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('epreuve_examen_id')->constrained('epreuves_examen')->cascadeOnDelete();
            $table->foreignId('salle_id')->constrained('salles')->restrictOnDelete();
            $table->unsignedSmallInteger('ordre')->default(0);

            $table->unique(['epreuve_examen_id', 'salle_id']);
            $table->index('salle_id');
        });

        Schema::create('epreuve_examen_surveillant', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('epreuve_examen_id')->constrained('epreuves_examen')->cascadeOnDelete();
            $table->foreignId('salle_id')->constrained('salles')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            $table->unique(['epreuve_examen_id', 'user_id']);
            $table->index('salle_id');
            $table->index('user_id');
        });

        DB::statement("ALTER TABLE plannings_examens ADD CONSTRAINT plannings_examens_check CHECK (statut IN ('brouillon', 'publie'))");
        DB::statement('ALTER TABLE epreuves_examen ADD CONSTRAINT epreuves_examen_check CHECK (fin > debut)');
    }

    public function down(): void
    {
        Schema::dropIfExists('epreuve_examen_surveillant');
        Schema::dropIfExists('epreuve_examen_salle');
        Schema::dropIfExists('epreuves_examen');
        Schema::dropIfExists('plannings_examens');
    }
};
