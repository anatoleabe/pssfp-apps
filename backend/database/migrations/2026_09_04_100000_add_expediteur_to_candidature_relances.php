<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Ajoute au journal des envois l'identité de l'expéditeur et le code rendu
 * par la passerelle.
 *
 * Sans ces deux colonnes, un échec se lit « Echo SMS a refusé l'envoi » sans
 * qu'on puisse distinguer un solde épuisé d'un Sender ID expiré — or le
 * Sender ID « PSSFP » a une date d'expiration, et un envoi peut basculer en
 * échec du jour au lendemain sans qu'aucun code n'ait changé. Conserver
 * l'expéditeur réellement utilisé et le code fournisseur rend ce diagnostic
 * immédiat depuis l'admin.
 *
 * `expediteur` sert les deux canaux : Sender ID pour un SMS, adresse
 * d'expédition pour un e-mail.
 *
 * Strictement additif : les deux colonnes sont nullables, les lignes déjà
 * enregistrées restent valides et s'afficheront avec un tiret.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidature_relances', function (Blueprint $table): void {
            $table->string('expediteur', 100)->nullable();
            $table->string('code_fournisseur', 10)->nullable();

            // Filtre « tous les envois partis avec tel Sender ID », utilisé
            // pour isoler les envois d'un masking expiré.
            $table->index('expediteur', 'candidature_relances_expediteur_idx');
        });
    }

    public function down(): void
    {
        Schema::table('candidature_relances', function (Blueprint $table): void {
            $table->dropIndex('candidature_relances_expediteur_idx');
            $table->dropColumn(['expediteur', 'code_fournisseur']);
        });
    }
};
