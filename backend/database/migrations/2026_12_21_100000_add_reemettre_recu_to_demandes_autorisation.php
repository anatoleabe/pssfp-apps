<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Règle du 25/11/2026 (« toute modification ou annulation [doit être] validée par l'admin ») : le service financier ne
 * décide plus seul de l'annulation d'un reçu ; il demande sa réémission, qu'un administrateur décide par le circuit du
 * lot 14c. La liste fermée des types s'élargit de `reemettre_recu`.
 *
 * `down()` échoue tant qu'il reste une demande `reemettre_recu` : une demande ne se supprime pas.
 */
return new class extends Migration
{
    private const TYPES_AVANT = "'rouvrir_etape_saisie', 'valider_quota', 'valider_maquette', 'valider_autre', 'annuler_pointage', 'revoquer_document', 'rejeter_etat_paie', 'deverrouiller_compte', 'accorder_moratoire', 'corriger_versement'";

    public function up(): void
    {
        // SQL brut, ADR-0011 : liste fermée des types de demande.
        DB::statement('ALTER TABLE demandes_autorisation DROP CONSTRAINT demandes_autorisation_check');
        DB::statement($this->contrainte(self::TYPES_AVANT.", 'reemettre_recu'"));
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE demandes_autorisation DROP CONSTRAINT demandes_autorisation_check');
        DB::statement($this->contrainte(self::TYPES_AVANT));
    }

    private function contrainte(string $types): string
    {
        return "ALTER TABLE demandes_autorisation ADD CONSTRAINT demandes_autorisation_check CHECK (
            numero ~ '^DM-[0-9]{4}-[0-9]{5}$'
            AND type IN ({$types})
            AND statut IN ('soumise', 'visa_attendu', 'rejetee', 'executee', 'echec_execution', 'retiree')
            AND length(trim(motif)) >= 10
            AND (empreinte IS NULL OR empreinte ~ '^[0-9a-f]{64}$')
        )";
    }
};
