<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * CHECK constraint sur `candidatures.mode_paiement`.
 *
 * V1 utilise uniquement `cremincam_agence`, `virement`, `especes`. La PR
 * Paiement Phase II ajoutera Orange Money, MTN Mobile Money et Visa.
 * On anticipe ces 3 valeurs dans la contrainte BDD dès maintenant pour
 * éviter une migration cassante quand la PR Paiement arrivera : il
 * suffira d'élargir le dropdown Filament et l'API.
 *
 * Valeurs autorisées :
 *   - cremincam_agence : paiement physique en agence CREMINCAM (V1, défaut)
 *   - virement         : virement bancaire (V1)
 *   - especes          : paiement en espèces au comptoir PSSFP (V1)
 *   - orange_money     : Orange Money (Phase II, anticipé)
 *   - mtn_money        : MTN Mobile Money (Phase II, anticipé)
 *   - carte_visa       : carte Visa / Mastercard via gateway en ligne (Phase II)
 *   - autre            : fallback exceptionnel
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE candidatures
            ADD CONSTRAINT candidatures_mode_paiement_check
            CHECK (
                mode_paiement IS NULL OR mode_paiement IN (
                    'cremincam_agence',
                    'virement',
                    'especes',
                    'orange_money',
                    'mtn_money',
                    'carte_visa',
                    'autre'
                )
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE candidatures DROP CONSTRAINT IF EXISTS candidatures_mode_paiement_check');
    }
};
