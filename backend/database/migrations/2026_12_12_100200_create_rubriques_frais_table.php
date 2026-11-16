<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rubriques de frais (reçu A4, R2) : pour une année académique et un niveau, les frais que couvre le barème (inscription,
 * bibliothèque, périscolaires, pension…), chacun avec son code d'imputation budgétaire (nul tant que la comptabilité ne l'a
 * pas donné), son montant et son ordre de répartition. La pension n'a pas de code fixe : son compte est celui de la promotion
 * (plan comptable de la RAF, un compte de scolarité par promotion). Un versement remplit les rubriques dans cet ordre, comme le reçu
 * ACMA. Le reçu fige sa ventilation à l'émission : modifier une rubrique ensuite ne change aucun reçu émis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rubriques_frais', function (Blueprint $table): void {
            $table->id();
            $table->string('annee_academique', 9);
            $table->string('niveau', 2);
            $table->string('libelle', 120);
            $table->string('code_imputation', 40)->nullable();
            // Pension : le compte est celui de la promotion de l'auditeur (campagnes_candidature.compte_scolarite), pas un code fixe.
            $table->boolean('compte_selon_promotion')->default(false);
            $table->unsignedInteger('montant');
            $table->unsignedSmallInteger('ordre');
            $table->boolean('actif')->default(true);
            $table->timestampsTz();

            $table->unique(['annee_academique', 'niveau', 'ordre']);
            $table->unique(['annee_academique', 'niveau', 'libelle']);
        });
        DB::statement("ALTER TABLE rubriques_frais ADD CONSTRAINT rubriques_frais_check CHECK (
            annee_academique ~ '^[0-9]{4}-[0-9]{4}$' AND niveau IN ('M1', 'M2') AND length(trim(libelle)) > 0
            AND (code_imputation IS NULL OR length(trim(code_imputation)) > 0)
            AND (NOT compte_selon_promotion OR code_imputation IS NULL)
        )");
        // Au plus une rubrique « compte selon la promotion » (la pension) par année et par niveau.
        DB::statement('CREATE UNIQUE INDEX rubriques_frais_pension_unique ON rubriques_frais (annee_academique, niveau) WHERE compte_selon_promotion');
    }

    public function down(): void
    {
        Schema::dropIfExists('rubriques_frais');
    }
};
