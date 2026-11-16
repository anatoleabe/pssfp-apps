<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 15i (spec de la réinscription des anciens, §4.2 ; avenant du 16/11/2026 à l'ADR-0014) : coordonnées et autres
 * colonnes de la fiche ACMA de l'auditeur, gardées brutes (texte nettoyé des espaces), et parcours ACMA par inscription.
 * Toutes facultatives : la reprise les remplit au prochain rejeu (format d'extraction /3).
 *
 * Strictement additive. `ADD COLUMN` ne déclenche pas les déclencheurs de ligne de l'archive.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const COLONNES_DOSSIER = [
        'telephone_source', 'mobile_source', 'email_source', 'email2_source', 'adresse_source', 'region_source',
        'region_bis_source', 'departement_source', 'numcni_source', 'expression_source', 'suspendu_source', 'idcampus_source',
    ];

    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        Schema::table('acma_dossiers', function (Blueprint $table): void {
            foreach (self::COLONNES_DOSSIER as $colonne) {
                $table->string($colonne, 255)->nullable();
            }
        });
        Schema::table('acma_inscriptions', function (Blueprint $table): void {
            $table->string('parcours_source', 100)->nullable();
        });
    }

    public function down(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        if (Schema::hasTable('acma_reprises') && DB::table('acma_reprises')->where('statut', 'gelee')->exists()) {
            throw new RuntimeException('Archive ACMA gelée : défaire ce lot demande une migration motivée (spec du lot 15, §7.3).');
        }
        Schema::table('acma_inscriptions', function (Blueprint $table): void {
            $table->dropColumn('parcours_source');
        });
        Schema::table('acma_dossiers', function (Blueprint $table): void {
            $table->dropColumn(self::COLONNES_DOSSIER);
        });
    }
};
