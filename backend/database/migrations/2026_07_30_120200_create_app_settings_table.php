<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Réglages applicatifs éditables depuis Filament, par opposition à `config/` +
 * `.env` qui exigent un accès serveur et un redéploiement.
 *
 * Premier usage : la liste des adresses mises en copie cachée de la
 * notification « nouvelle candidature soumise ». Volontairement une table
 * clé/valeur JSONB générique plutôt qu'un package dédié — aucune dépendance
 * Composer supplémentaire, et le typage reste porté par le formulaire Filament.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();
            $table->jsonb('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
