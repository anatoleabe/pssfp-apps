<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Référentiels Module 5 — Candidatures.
 *
 * - pays                   : 200+ pays (ISO-2 + indicatif téléphonique)
 * - regions_cameroun       : 11 régions + 1 « Z AUTRES » (étranger) avec quota d'admission
 * - departements_cameroun  : 58 départements rattachés à une région
 *
 * Source : docs/migration-candidature/seed-reference-data.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pays', function (Blueprint $table) {
            $table->string('code_iso', 2)->primary();
            $table->string('nom', 100);
            $table->string('indicatif', 10);
            $table->timestamps();
            $table->index('nom');
        });

        Schema::create('regions_cameroun', function (Blueprint $table) {
            $table->string('code', 30)->primary();
            $table->string('nom', 50);
            $table->decimal('quota_admission', 5, 4)->nullable();
            $table->string('chef_lieu', 50)->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();
        });

        Schema::create('departements_cameroun', function (Blueprint $table) {
            $table->string('code', 50)->primary();
            $table->string('nom', 50);
            $table->string('chef_lieu', 100)->nullable();
            $table->string('region_code', 30);
            $table->timestamps();
            $table->foreign('region_code')
                ->references('code')->on('regions_cameroun')
                ->onDelete('cascade');
            $table->index('region_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departements_cameroun');
        Schema::dropIfExists('regions_cameroun');
        Schema::dropIfExists('pays');
    }
};
