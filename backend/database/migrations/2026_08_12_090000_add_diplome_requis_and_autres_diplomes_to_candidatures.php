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
 * `form_version` discrimine l'ancien formulaire du nouveau. La migration pose
 * DEFAULT 1 et s'arrête là : la bascule à 2 est un acte de déploiement séparé
 * (`php artisan candidatures:activer-formulaire-v2`), joué APRÈS la mise en
 * ligne du bundle candidature.
 *
 * Raison : `deploy.sh` exécute les migrations avant deux builds Next.js, soit
 * plusieurs minutes pendant lesquelles l'ancien front est encore servi. Si la
 * base imposait déjà les nouvelles règles, un candidat créant son dossier dans
 * cette fenêtre serait bloqué à la soumission sur des champs que son écran
 * n'affiche pas. Avec DEFAULT 1, il reste sous l'ancien jeu de règles, qu'il
 * peut satisfaire intégralement.
 *
 * Mode de défaillance choisi : si la commande d'activation est oubliée, rien
 * ne casse — les dossiers continuent simplement sur l'ancien formulaire.
 *
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
        // Pas de `->after()` : le modificateur n'existe que dans la grammaire
        // MySQL et serait silencieusement ignoré par PostgreSQL. Les colonnes
        // sont donc ajoutées en fin de table, ce qui n'a aucune incidence.
        Schema::table('candidatures', function (Blueprint $table): void {
            $table->string('diplome_requis', 30)->nullable();
            $table->smallInteger('annee_diplome_requis')->nullable();
            $table->string('domaine_diplome_requis', 30)->nullable();
            $table->string('specialite_diplome_requis', 100)->nullable();
            $table->string('institut_diplome_requis', 150)->nullable();
            $table->jsonb('autres_diplomes')->nullable();
            $table->jsonb('formations_professionnelles')->nullable();
            // NOT NULL implicite (pas de ->nullable()), avec un défaut constant :
            // PostgreSQL 11+ n'effectue donc aucune réécriture de table.
            $table->smallInteger('form_version')->default(1);
        });

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
