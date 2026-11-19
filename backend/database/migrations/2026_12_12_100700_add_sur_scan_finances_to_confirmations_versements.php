<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Confirmation sur le scan du service financier (décision du 19/11/2026, spec
 * module-4-scolarite-encaissement-confirmation §10) : la scolarité compare le reçu bancaire original au scan joint à
 * l'encaissement, sans en déposer un second. `sur_scan_finances` dit que la pièce vérifiée (`justificatif_path`) est
 * celle du service financier. Ajouter une colonne ne déclenche pas le déclencheur d'immuabilité (il porte sur les
 * lignes) ; les confirmations existantes restent à faux, leur scan étant celui de la scolarité ou de la reprise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('confirmations_versements', function (Blueprint $table): void {
            $table->boolean('sur_scan_finances')->default(false);
        });
        DB::statement('ALTER TABLE confirmations_versements ADD CONSTRAINT confirmations_versements_scan_finances_check CHECK (NOT sur_scan_finances OR justificatif_path IS NOT NULL)');
    }

    public function down(): void
    {
        // Les confirmations gardent leur pièce (`justificatif_path`) : seule l'indication de sa provenance se perd, le journal la garde.
        DB::statement('ALTER TABLE confirmations_versements DROP CONSTRAINT confirmations_versements_scan_finances_check');
        Schema::table('confirmations_versements', function (Blueprint $table): void {
            $table->dropColumn('sur_scan_finances');
        });
    }
};
