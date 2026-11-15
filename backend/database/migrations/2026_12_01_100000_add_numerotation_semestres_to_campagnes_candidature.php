<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Réglage de promotion : numérotation des semestres à l'affichage, « master »
 * (S1 à S4) ou « lmd » (S7 à S10, comme l'université). Affichage seulement :
 * le semestre enregistré dans les maquettes ne change pas. Défaut : master.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campagnes_candidature', function (Blueprint $table): void {
            $table->string('numerotation_semestres', 10)->default('master');
        });
        DB::statement("ALTER TABLE campagnes_candidature ADD CONSTRAINT campagnes_candidature_numerotation_semestres_check CHECK (numerotation_semestres IN ('master', 'lmd'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE campagnes_candidature DROP CONSTRAINT IF EXISTS campagnes_candidature_numerotation_semestres_check');
        Schema::table('campagnes_candidature', function (Blueprint $table): void {
            $table->dropColumn('numerotation_semestres');
        });
    }
};
