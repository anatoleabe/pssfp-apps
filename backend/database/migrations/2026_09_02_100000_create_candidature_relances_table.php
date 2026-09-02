<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Journal des relances envoyées aux candidats dont le dossier n'avance plus.
 *
 * Sert deux besoins :
 * - Anti-doublon : une relance déjà envoyée pour une cause donnée ne doit pas
 *   repartir au lancement suivant de la commande. Un candidat relancé deux
 *   fois dans la même journée perdrait confiance dans l'institution.
 * - Traçabilité : qui a été relancé, quand, pourquoi, et avec quel résultat.
 *
 * Le numéro de téléphone n'est pas dupliqué ici : il est déjà sur la
 * candidature, et le redonder multiplierait les surfaces exposant de la PII.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidature_relances', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('candidature_id');

            // Cause du blocage au moment de la relance : 'ready' (dossier
            // complet non soumis) ou 'photo_only' (seule la photo manque).
            $table->string('cause', 20);
            $table->string('canal', 10)->default('sms');
            $table->string('statut', 10);
            $table->text('erreur')->nullable();
            $table->timestampTz('sent_at');
            $table->timestampsTz();

            $table->foreign('candidature_id')
                ->references('id')->on('candidatures')
                ->onDelete('cascade');

            // Lookup principal : « ce dossier a-t-il déjà été relancé pour
            // cette cause ? », joué une fois par candidature à chaque envoi.
            $table->index(['candidature_id', 'cause'], 'candidature_relances_dossier_cause_idx');
            $table->index('sent_at', 'candidature_relances_sent_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidature_relances');
    }
};
