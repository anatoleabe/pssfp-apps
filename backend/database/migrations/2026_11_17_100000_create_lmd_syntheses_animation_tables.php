<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Primes d'animation en ligne (spec module 4, lot 13b-2 ; D25 ; sql/16 : synthese_animation, activite_animation).
 * Une synthèse par enseignant, cours et année ; payée une seule fois (ligne d'état de paie).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('syntheses_animation', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('annee_academique', 9);
            $table->foreignId('enseignant_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('maquette_ecue_id')->constrained('maquette_ecues')->restrictOnDelete();
            $table->foreignId('programmation_id')->nullable()->constrained('programmations_enseignant')->nullOnDelete();
            $table->date('periode_du');
            $table->date('periode_au');
            $table->string('source', 16)->default('saisie');
            $table->string('groupe_whatsapp', 150)->nullable();
            $table->string('justificatif', 255)->nullable();
            $table->foreignId('importe_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('importe_at')->nullable();
            $table->string('statut', 10)->default('brouillon');
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('valide_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('valide_at')->nullable();
            $table->string('motif_rejet', 500)->nullable();
            $table->foreignId('ligne_paie_id')->nullable()->constrained('lignes_etat_paie')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['annee_academique', 'enseignant_id', 'maquette_ecue_id']);
            $table->index('enseignant_id');
            $table->index('maquette_ecue_id');
            $table->index('programmation_id');
            $table->index(['statut', 'periode_au']);
            $table->index('ligne_paie_id');
            foreach (['importe_par', 'cree_par', 'valide_par'] as $c) {
                $table->index($c);
            }
        });

        Schema::create('activites_animation', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('synthese_id')->constrained('syntheses_animation')->cascadeOnDelete();
            $table->string('famille', 12);
            $table->string('type_activite', 80);
            $table->unsignedInteger('attendu');
            $table->unsignedInteger('realise')->default(0);
            $table->timestampsTz();

            $table->unique(['synthese_id', 'type_activite']);
        });

        // SQL brut, ADR-0011 : valeurs fermées et circuit cohérent ; seule une synthèse validée est payée.
        DB::statement("ALTER TABLE syntheses_animation ADD CONSTRAINT syntheses_animation_check CHECK (source IN ('saisie', 'export_whatsapp') AND statut IN ('brouillon', 'valide', 'rejete') AND periode_au >= periode_du AND (statut <> 'rejete' OR motif_rejet IS NOT NULL) AND (statut <> 'valide' OR valide_at IS NOT NULL) AND (ligne_paie_id IS NULL OR statut = 'valide'))");
        DB::statement("ALTER TABLE activites_animation ADD CONSTRAINT activites_animation_check CHECK (famille IN ('contribution', 'animation') AND attendu > 0 AND realise >= 0)");
    }

    public function down(): void
    {
        if (DB::table('syntheses_animation')->whereNotNull('ligne_paie_id')->exists()) {
            throw new RuntimeException('Des primes d\'animation ont été portées sur un état de paie : retour arrière refusé (historique de paie).');
        }
        Schema::dropIfExists('activites_animation');
        Schema::dropIfExists('syntheses_animation');
    }
};
