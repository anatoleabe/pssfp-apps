<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index sur la cible des redirections : la fiche d'un document liste ses
 * anciennes adresses (`to_path = '/documents/{slug}'`, onglet « Anciennes
 * adresses »), et l'écran des redirections cherche sur la cible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('library_redirects', function (Blueprint $table): void {
            $table->index('to_path');
        });
    }

    public function down(): void
    {
        Schema::table('library_redirects', function (Blueprint $table): void {
            $table->dropIndex(['to_path']);
        });
    }
};
