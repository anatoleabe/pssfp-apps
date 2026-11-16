<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reçu A4 en deux exemplaires (R1) : la ventilation du versement, figée à l'émission. Une liste de lignes {libelle, code,
 * du, ce_versement, total_verse, reste}. Nulle pour un reçu déjà émis, qui s'affiche avec sa seule ligne « objet ».
 *
 * Ajout seul. Le déclencheur d'immuabilité des reçus (lot 4) refuse déjà toute modification : la colonne s'écrit à
 * l'insertion, jamais ensuite. `down()` refuse dès qu'un reçu porte une ventilation : c'est le contenu d'une pièce.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recus_versements', function (Blueprint $table): void {
            $table->jsonb('ventilation')->nullable();
        });
        DB::statement("ALTER TABLE recus_versements ADD CONSTRAINT recus_versements_ventilation_check CHECK (ventilation IS NULL OR jsonb_typeof(ventilation) = 'array')");
    }

    public function down(): void
    {
        if (DB::table('recus_versements')->whereNotNull('ventilation')->exists()) {
            throw new RuntimeException('Des reçus portent leur ventilation : ce sont des pièces. Revenez au code précédent sans annuler la migration.');
        }
        DB::statement('ALTER TABLE recus_versements DROP CONSTRAINT IF EXISTS recus_versements_ventilation_check');
        Schema::table('recus_versements', function (Blueprint $table): void {
            $table->dropColumn('ventilation');
        });
    }
};
