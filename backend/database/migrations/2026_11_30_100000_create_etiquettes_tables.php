<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Étiquettes libres posées sur les auditeurs (« Campus B », « Président de
 * l'amicale »…), pour les retrouver dans la liste.
 *
 * `cle` est le nom en minuscules, calculé par le modèle : l'unicité ne dépend
 * ni de la casse ni des réglages régionaux du serveur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('etiquettes', function (Blueprint $table): void {
            $table->id();
            $table->string('nom', 60);
            // Plus large que le nom : une minuscule peut compter plus de caractères (« İ »).
            $table->string('cle', 120)->unique();
            $table->string('couleur', 16)->default('gray');
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('auditeur_etiquette', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('auditeur_id')->constrained('auditeurs')->cascadeOnDelete();
            $table->foreignId('etiquette_id')->constrained('etiquettes')->cascadeOnDelete();
            $table->foreignId('ajoute_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['auditeur_id', 'etiquette_id']);
            $table->index('etiquette_id');
        });

        // SQL brut autorisé par l'ADR-0010 (contraintes CHECK).
        DB::statement("ALTER TABLE etiquettes ADD CONSTRAINT etiquettes_couleur_check CHECK (couleur IN ('gray', 'primary', 'info', 'success', 'warning', 'danger'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('auditeur_etiquette');
        Schema::dropIfExists('etiquettes');
    }
};
