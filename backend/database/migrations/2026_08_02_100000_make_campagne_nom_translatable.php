<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rend le nom de campagne traduisible (ADR-0006).
 *
 * Le portail anglais affichait « Année académique 2026-2027 » en titre de page,
 * parce que ce libellé vient de la base et n'existait qu'en français. Le champ
 * rejoint la stratégie JSONB déjà appliquée aux pages, articles et assets.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Conversion en JSONB en enveloppant la valeur existante sous la clé
        // `fr` — aucune donnée n'est perdue, aucune saisie n'est requise.
        DB::statement(<<<'SQL'
            ALTER TABLE campagnes_candidature
            ALTER COLUMN nom TYPE JSONB
            USING jsonb_build_object('fr', nom)
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE campagnes_candidature
            ALTER COLUMN nom TYPE VARCHAR(100)
            USING COALESCE(nom->>'fr', nom->>'en', '')
        SQL);
    }
};
