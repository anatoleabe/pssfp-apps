<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Communiqué conjoint signé (MINFI + Recteur UY2-Soa) rattaché à la campagne.
 *
 * Porté par la campagne et non par un asset global : chaque promotion a son
 * propre communiqué, et la direction doit pouvoir le remplacer depuis Filament
 * sans redéploiement (arbitrage Anatole 30/07/2026).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campagnes_candidature', function (Blueprint $table): void {
            $table->string('communique_pdf_path', 255)->nullable()->after('results_at');
            $table->string('communique_reference', 100)->nullable()->after('communique_pdf_path');
            $table->date('communique_signed_at')->nullable()->after('communique_reference');
        });
    }

    public function down(): void
    {
        Schema::table('campagnes_candidature', function (Blueprint $table): void {
            $table->dropColumn([
                'communique_pdf_path',
                'communique_reference',
                'communique_signed_at',
            ]);
        });
    }
};
