<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Étend le journal des relances aux notifications déclenchées à la main
 * depuis l'admin (bouton « Notifier les candidats sélectionnés »).
 *
 * Une relance automatique n'a pas besoin de conserver son texte : il est figé
 * dans la configuration. Une notification manuelle, si : c'est un message
 * rédigé par un agent, envoyé à des dizaines de personnes au nom de
 * l'institution. Savoir qui a écrit quoi, à qui et quand est la condition
 * pour que cette fonction soit utilisable sans risque.
 *
 * Strictement additif : toutes les colonnes sont nullables, les lignes déjà
 * enregistrées restent valides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidature_relances', function (Blueprint $table): void {
            $table->text('message')->nullable();
            $table->string('sujet', 200)->nullable();
            $table->unsignedBigInteger('envoye_par')->nullable();

            $table->foreign('envoye_par')
                ->references('id')->on('users')
                ->onDelete('set null');

            $table->index('envoye_par', 'candidature_relances_envoye_par_idx');
        });
    }

    public function down(): void
    {
        Schema::table('candidature_relances', function (Blueprint $table): void {
            $table->dropForeign(['envoye_par']);
            $table->dropIndex('candidature_relances_envoye_par_idx');
            $table->dropColumn(['message', 'sujet', 'envoye_par']);
        });
    }
};
