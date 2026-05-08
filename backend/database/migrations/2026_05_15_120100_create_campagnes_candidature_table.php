<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Campagnes de candidature.
 *
 * Une campagne = une année académique d'admission (ex: P14 / 2026).
 * - prefix_numero : préfixe du numéro de dossier (ex: « P14026- » pour P14/2026).
 * - status        : draft | open | closed | archived.
 * - max_voeux     : 1 par défaut (cf. spec v1.1 §M1, choix après analyse P13).
 *
 * Source : docs/specs/module-5-candidatures.md §1.1 D1, §M1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campagnes_candidature', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('slug', 50)->unique();
            $table->string('prefix_numero', 20);
            $table->string('nom', 100);
            $table->smallInteger('promotion_numero');
            $table->timestampTz('opens_at');
            $table->timestampTz('closes_at');
            $table->timestampTz('results_at')->nullable();
            $table->string('status', 20)->default('draft');
            $table->smallInteger('max_voeux')->default(1);
            $table->timestamps();

            $table->index(['status', 'opens_at', 'closes_at'], 'campagnes_status_dates_idx');
        });

        // Contrainte CHECK Postgres sur status (équivalent enum).
        DB::statement(<<<'SQL'
            ALTER TABLE campagnes_candidature
            ADD CONSTRAINT campagnes_status_check
            CHECK (status IN ('draft', 'open', 'closed', 'archived'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('campagnes_candidature');
    }
};
