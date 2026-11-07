<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Assiduité (spec module 4, lot 10 ; D12, D13 ; RG-024). La note d'assiduité (note de TP au
 * PSSFP) se calcule sur les séances tenues ; une absence justifiée compte comme présente.
 *
 * Strictement additif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regles_assiduite', function (Blueprint $table): void {
            $table->id();
            $table->string('annee_academique', 9)->unique();
            $table->unsignedSmallInteger('temps_requis_pct')->default(75);
            $table->unsignedSmallInteger('retard_tolere_min')->default(15);
            $table->boolean('justifiee_comptee')->default(true);
            $table->decimal('note_max', 5, 2)->default(20);
            $table->timestampsTz();
        });

        Schema::create('seances', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('maquette_ecue_id')->constrained('maquette_ecues')->restrictOnDelete();
            $table->date('date_seance');
            $table->time('debut');
            $table->time('fin');
            $table->string('modalite', 12)->default('hybride');
            $table->string('salle', 120)->nullable();
            $table->string('lien_reunion', 500)->nullable();
            $table->string('statut', 10)->default('prevue');
            $table->string('motif_annulation', 500)->nullable();
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['maquette_ecue_id', 'date_seance']);
            $table->index('cree_par');
        });

        Schema::create('pointages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seance_id')->constrained('seances')->restrictOnDelete();
            $table->foreignId('inscription_pedagogique_id')->constrained('inscriptions_pedagogiques')->restrictOnDelete();
            $table->string('statut', 10);
            $table->string('mode', 10)->default('manuel');
            $table->unsignedSmallInteger('duree_min')->nullable();
            $table->string('motif', 500)->nullable();
            $table->foreignId('saisi_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['seance_id', 'inscription_pedagogique_id']);
            $table->index('inscription_pedagogique_id');
            $table->index('saisi_par');
        });

        // SQL brut, ADR-0011 : états fermés, horaires cohérents, justification motivée.
        DB::statement('ALTER TABLE regles_assiduite ADD CONSTRAINT regles_assiduite_valeurs_check CHECK (temps_requis_pct BETWEEN 1 AND 100 AND note_max > 0 AND note_max <= 20)');
        DB::statement("ALTER TABLE seances ADD CONSTRAINT seances_valeurs_check CHECK (modalite IN ('presentiel', 'distanciel', 'hybride') AND statut IN ('prevue', 'tenue', 'annulee') AND fin > debut)");
        DB::statement("ALTER TABLE seances ADD CONSTRAINT seances_annulation_check CHECK (statut <> 'annulee' OR motif_annulation IS NOT NULL)");
        DB::statement("ALTER TABLE pointages ADD CONSTRAINT pointages_valeurs_check CHECK (statut IN ('present', 'absent', 'justifie') AND mode IN ('signature', 'manuel', 'zoom'))");
        DB::statement("ALTER TABLE pointages ADD CONSTRAINT pointages_justification_check CHECK (statut <> 'justifie' OR motif IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('pointages');
        Schema::dropIfExists('seances');
        Schema::dropIfExists('regles_assiduite');
    }
};
