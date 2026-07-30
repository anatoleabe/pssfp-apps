<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Traçabilité du dépôt physique du dossier au bureau de la scolarité
 * (Yaoundé-Messa, porte 231), exigé par le communiqué n° 90001121 du 15/07/2026.
 *
 * Le parcours reste « en ligne d'abord » : toute candidature naît sur
 * apply.pssfp.org, le candidat imprime sa fiche, complète son dossier papier et
 * le dépose. Un agent de réception coche ensuite le dépôt dans l'admin — en
 * différé, éventuellement par lot pour toute une journée de réception. D'où une
 * date saisissable (`depot_physique_at`) et non un simple booléen `now()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidatures', function (Blueprint $table): void {
            $table->timestampTz('depot_physique_at')->nullable()->after('submitted_at');
            $table->foreignId('depot_physique_by')->nullable()->after('depot_physique_at')
                ->constrained('users')->nullOnDelete();
            $table->string('depot_physique_observation', 255)->nullable()->after('depot_physique_by');

            $table->index('depot_physique_at', 'candidatures_depot_physique_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('candidatures', function (Blueprint $table): void {
            $table->dropIndex('candidatures_depot_physique_at_index');
            $table->dropConstrainedForeignId('depot_physique_by');
            $table->dropColumn(['depot_physique_at', 'depot_physique_observation']);
        });
    }
};
