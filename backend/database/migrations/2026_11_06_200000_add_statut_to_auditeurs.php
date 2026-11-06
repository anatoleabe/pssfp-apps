<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Statut de l'auditeur (spec module 4, lot 2 §4) : informatif dans ce lot.
 * Tous les auditeurs existants passent « actif ». Strictement additif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auditeurs', function (Blueprint $table): void {
            $table->string('statut', 20)->default('actif');
            $table->string('statut_motif', 500)->nullable();
            $table->date('statut_depuis')->nullable();
            $table->foreignId('statut_par')->nullable()->constrained('users')->nullOnDelete();
            $table->index('statut');
        });

        // SQL brut autorisé par l'ADR-0010 (contraintes CHECK).
        DB::statement("ALTER TABLE auditeurs ADD CONSTRAINT auditeurs_statut_check CHECK (statut IN ('actif', 'suspendu', 'abandon', 'exclu', 'diplome'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE auditeurs DROP CONSTRAINT IF EXISTS auditeurs_statut_check');
        Schema::table('auditeurs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('statut_par');
            $table->dropIndex(['statut']);
            $table->dropColumn(['statut', 'statut_motif', 'statut_depuis']);
        });
    }
};
