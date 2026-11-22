<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * PV de délibération archivé au registre des documents délivrés, et scan du PV signé
 * (spec module 4, lot 16a).
 *
 * - Un PV n'a ni auditeur ni inscription : les deux colonnes deviennent facultatives,
 *   une contrainte garde l'obligation pour tout autre document.
 * - Un seul PV valide par délibération.
 *
 * Strictement additif. SQL brut limité aux contraintes et à l'index partiel (ADR-0011).
 */
return new class extends Migration
{
    private const TYPES_AVANT = "'releve_notes', 'attestation_reussite', 'certificat_scolarite', 'autorisation_depot'";

    public function up(): void
    {
        Schema::table('documents_delivres', function (Blueprint $table): void {
            $table->unsignedBigInteger('auditeur_id')->nullable()->change();
            $table->unsignedBigInteger('inscription_annuelle_id')->nullable()->change();
        });
        Schema::table('deliberations', function (Blueprint $table): void {
            $table->string('pv_signe_chemin', 255)->nullable();
            $table->char('pv_signe_sha256', 64)->nullable();
        });

        DB::statement('ALTER TABLE documents_delivres DROP CONSTRAINT documents_delivres_type_check');
        DB::statement('ALTER TABLE documents_delivres ADD CONSTRAINT documents_delivres_type_check CHECK (type IN ('.self::TYPES_AVANT.", 'pv_deliberation'))");
        DB::statement("ALTER TABLE documents_delivres ADD CONSTRAINT documents_delivres_objet_check CHECK (
            (type = 'pv_deliberation' AND deliberation_id IS NOT NULL AND auditeur_id IS NULL AND inscription_annuelle_id IS NULL)
            OR (type <> 'pv_deliberation' AND auditeur_id IS NOT NULL AND inscription_annuelle_id IS NOT NULL)
        )");
        DB::statement("CREATE UNIQUE INDEX documents_delivres_pv_unique ON documents_delivres (deliberation_id) WHERE type = 'pv_deliberation' AND revoque_at IS NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS documents_delivres_pv_unique');
        DB::statement('ALTER TABLE documents_delivres DROP CONSTRAINT IF EXISTS documents_delivres_objet_check');
        DB::table('documents_delivres')->where('type', 'pv_deliberation')->delete();
        DB::statement('ALTER TABLE documents_delivres DROP CONSTRAINT documents_delivres_type_check');
        DB::statement('ALTER TABLE documents_delivres ADD CONSTRAINT documents_delivres_type_check CHECK (type IN ('.self::TYPES_AVANT.'))');

        Schema::table('deliberations', function (Blueprint $table): void {
            $table->dropColumn(['pv_signe_chemin', 'pv_signe_sha256']);
        });
        Schema::table('documents_delivres', function (Blueprint $table): void {
            $table->unsignedBigInteger('auditeur_id')->nullable(false)->change();
            $table->unsignedBigInteger('inscription_annuelle_id')->nullable(false)->change();
        });
    }
};
