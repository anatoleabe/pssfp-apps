<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Agent ayant ajouté un retenu depuis l'admin, sans passage par le portail de
 * candidature. NULL pour tout dossier déposé par un candidat.
 *
 * Strictement additif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidatures', function (Blueprint $table): void {
            $table->foreignId('ajoute_par')->nullable()->constrained('users')->nullOnDelete();
            $table->index('ajoute_par');
        });
    }

    public function down(): void
    {
        Schema::table('candidatures', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ajoute_par');
        });
    }
};
