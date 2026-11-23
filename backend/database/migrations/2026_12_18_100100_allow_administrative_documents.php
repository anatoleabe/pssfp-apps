<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Lot 26 (modèles du 23/11/2026) : attestation de recherche et lettre de stage délivrées par la scolarité.
 * La lettre de stage se délivre plusieurs fois pour une même inscription (une par structure d'accueil).
 * SQL brut limité à la contrainte et à l'index partiel (ADR-0011).
 */
return new class extends Migration
{
    private const TYPES_AVANT = "'releve_notes', 'attestation_reussite', 'certificat_scolarite', 'autorisation_depot', 'pv_deliberation'";

    private const UNIQUE = 'CREATE UNIQUE INDEX documents_delivres_valide_unique ON documents_delivres (type, inscription_annuelle_id, coalesce(deliberation_id, 0)) WHERE revoque_at IS NULL';

    public function up(): void
    {
        DB::statement('ALTER TABLE documents_delivres DROP CONSTRAINT documents_delivres_type_check');
        DB::statement('ALTER TABLE documents_delivres ADD CONSTRAINT documents_delivres_type_check CHECK (type IN ('.self::TYPES_AVANT.", 'attestation_recherche', 'lettre_stage'))");
        DB::statement('DROP INDEX documents_delivres_valide_unique');
        DB::statement(self::UNIQUE." AND type <> 'lettre_stage'");
    }

    public function down(): void
    {
        DB::table('documents_delivres')->whereIn('type', ['attestation_recherche', 'lettre_stage'])->delete();
        DB::statement('DROP INDEX documents_delivres_valide_unique');
        DB::statement(self::UNIQUE);
        DB::statement('ALTER TABLE documents_delivres DROP CONSTRAINT documents_delivres_type_check');
        DB::statement('ALTER TABLE documents_delivres ADD CONSTRAINT documents_delivres_type_check CHECK (type IN ('.self::TYPES_AVANT.'))');
    }
};
