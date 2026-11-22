<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Délibération de semestre (spec lot 17a) : rang du semestre dans le master, de 1 à 4 ;
 * vide pour une délibération annuelle. Les semestres 1 et 2 sont au M1, 3 et 4 au M2.
 *
 * Strictement additif. Contrainte en SQL comme pour les autres tables du LMD (ADR-0011).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliberations', function (Blueprint $table): void {
            $table->unsignedSmallInteger('semestre')->nullable();
        });
        DB::statement("ALTER TABLE deliberations ADD CONSTRAINT deliberations_semestre_check CHECK (semestre IS NULL OR (niveau = 'M1' AND semestre IN (1, 2)) OR (niveau = 'M2' AND semestre IN (3, 4)))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE deliberations DROP CONSTRAINT IF EXISTS deliberations_semestre_check');
        Schema::table('deliberations', function (Blueprint $table): void {
            $table->dropColumn('semestre');
        });
    }
};
