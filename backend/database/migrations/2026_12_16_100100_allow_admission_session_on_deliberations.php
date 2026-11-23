<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Jury d'admission du Master 2 (spec lot 24c) : session « admission » sur les délibérations
 * (M2, année entière seulement) et sur les acquis d'UE qu'elles produisent.
 *
 * Strictement additif. Contraintes en SQL comme pour les autres tables du LMD (ADR-0011).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->contraintes("'normale', 'rattrapage', 'admission'");
        DB::statement("ALTER TABLE deliberations ADD CONSTRAINT deliberations_admission_check CHECK (session <> 'admission' OR (niveau = 'M2' AND semestre IS NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE deliberations DROP CONSTRAINT IF EXISTS deliberations_admission_check');
        $this->contraintes("'normale', 'rattrapage'");
    }

    private function contraintes(string $sessions): void
    {
        DB::statement('ALTER TABLE deliberations DROP CONSTRAINT IF EXISTS deliberations_valeurs_check');
        DB::statement("ALTER TABLE deliberations ADD CONSTRAINT deliberations_valeurs_check CHECK (niveau IN ('M1', 'M2') AND session IN ({$sessions}) AND statut IN ('simulation', 'arretee', 'rectifiee') AND (decision_jury IS NULL OR decision_jury IN ('deliberation', 'sans_deliberation')))");
        DB::statement('ALTER TABLE acquis_ue DROP CONSTRAINT IF EXISTS acquis_ue_valeurs_check');
        DB::statement("ALTER TABLE acquis_ue ADD CONSTRAINT acquis_ue_valeurs_check CHECK (niveau IN ('M1', 'M2') AND session IN ({$sessions}) AND origine IN ('evaluation', 'deliberation', 'reprise', 'correspondance'))");
    }
};
