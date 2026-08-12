<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Évolution « diplôme requis & autres diplômes »
 * (cf. docs/specs/module-5-evolution-diplomes-2026-08.md).
 *
 * Migration strictement additive : toutes les nouvelles colonnes métier sont
 * NULLABLE, aucune ligne de production n'est réécrite ni invalidée.
 *
 * `form_version` discrimine l'ancien formulaire du nouveau :
 *   1. ADD COLUMN ... DEFAULT 1        -> toutes les lignes existantes prennent 1
 *   2. ALTER COLUMN ... SET DEFAULT 2  -> toute ligne insérée ensuite prend 2
 * Aucun code applicatif ne fixe cette colonne : le défaut Postgres suffit, et
 * CandidatureService la maintient en liste noire du PUT.
 *
 * Les contraintes CHECK tolèrent NULL (sémantique Postgres) : elles ne peuvent
 * donc pas rejeter une ligne préexistante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidatures', function (Blueprint $table): void {
            $table->string('diplome_requis', 30)->nullable()->after('annee_diplome');
            $table->smallInteger('annee_diplome_requis')->nullable()->after('diplome_requis');
            $table->string('domaine_diplome_requis', 30)->nullable()->after('annee_diplome_requis');
            $table->string('specialite_diplome_requis', 100)->nullable()->after('domaine_diplome_requis');
            $table->string('institut_diplome_requis', 150)->nullable()->after('specialite_diplome_requis');
            $table->jsonb('autres_diplomes')->nullable()->after('institut_diplome_requis');
            $table->jsonb('formations_professionnelles')->nullable()->after('autres_diplomes');
            $table->smallInteger('form_version')->default(1)->after('statut');
        });

        DB::statement('ALTER TABLE candidatures ALTER COLUMN form_version SET DEFAULT 2');
        DB::statement('ALTER TABLE candidatures ALTER COLUMN form_version SET NOT NULL');

        DB::statement(<<<'SQL'
            ALTER TABLE candidatures
            ADD CONSTRAINT candidatures_diplome_requis_check
            CHECK (diplome_requis IN ('licence-bachelor', 'master'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE candidatures
            ADD CONSTRAINT candidatures_domaine_diplome_requis_check
            CHECK (domaine_diplome_requis IN ('droit', 'economie', 'gestion', 'autres'))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE candidatures DROP CONSTRAINT IF EXISTS candidatures_diplome_requis_check');
        DB::statement('ALTER TABLE candidatures DROP CONSTRAINT IF EXISTS candidatures_domaine_diplome_requis_check');

        Schema::table('candidatures', function (Blueprint $table): void {
            $table->dropColumn([
                'diplome_requis',
                'annee_diplome_requis',
                'domaine_diplome_requis',
                'specialite_diplome_requis',
                'institut_diplome_requis',
                'autres_diplomes',
                'formations_professionnelles',
                'form_version',
            ]);
        });
    }
};
