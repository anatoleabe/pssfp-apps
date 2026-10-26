<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Ce que le candidat doit retrouver dans son espace une fois la décision rendue.
 *
 * Jusqu'ici, le motif d'un refus et le commentaire d'une admission partaient
 * par e-mail puis ne vivaient plus que dans le journal d'activité — et, pour un
 * refus groupé, rattachés au lot plutôt qu'au dossier. On les porte désormais
 * sur le dossier lui-même.
 *
 * Les consignes aux retenus (inscription, rentrée) changent à chaque promotion :
 * elles se saisissent sur la campagne, depuis l'admin, sans redéploiement.
 *
 * Strictement additif : colonnes nullables, aucune ligne existante invalidée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidatures', function (Blueprint $table): void {
            $table->text('decision_motif')->nullable();
            $table->text('decision_commentaire')->nullable();
        });

        Schema::table('campagnes_candidature', function (Blueprint $table): void {
            $table->text('consignes_retenus')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('campagnes_candidature', function (Blueprint $table): void {
            $table->dropColumn('consignes_retenus');
        });

        Schema::table('candidatures', function (Blueprint $table): void {
            $table->dropColumn(['decision_motif', 'decision_commentaire']);
        });
    }
};
