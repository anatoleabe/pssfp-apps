<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Lot 14d-2 (D30, D31) : échecs de connexion consécutifs, verrouillage des comptes et sa demande de levée. */
return new class extends Migration
{
    private const TYPES_14C = "'rouvrir_etape_saisie', 'valider_quota', 'valider_maquette', 'valider_autre', 'annuler_pointage', 'revoquer_document', 'rejeter_etat_paie'";

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('echecs_connexion')->default(0);
            $table->timestampTz('verrouille_le')->nullable();
            // Fin d'un verrou temporaire ; vide pour un verrou manuel (levé par un administrateur).
            $table->timestampTz('verrouille_jusqua')->nullable();
            $table->string('verrouille_motif', 255)->nullable();
            $table->index('verrouille_le');
        });

        // SQL brut, ADR-0011 : la liste fermée des types de demandes s'élargit au déverrouillage d'un compte.
        DB::statement('ALTER TABLE demandes_autorisation DROP CONSTRAINT demandes_autorisation_check');
        DB::statement($this->contrainte(self::TYPES_14C.", 'deverrouiller_compte'"));
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE demandes_autorisation DROP CONSTRAINT demandes_autorisation_check');
        DB::statement($this->contrainte(self::TYPES_14C));
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['verrouille_le']);
            $table->dropColumn(['echecs_connexion', 'verrouille_le', 'verrouille_jusqua', 'verrouille_motif']);
        });
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
