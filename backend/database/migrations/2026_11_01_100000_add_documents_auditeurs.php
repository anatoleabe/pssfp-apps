<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Documents remis aux auditeurs (accès FOAD, lettre d'admission).
 *
 * - candidatures.frais_inscription_payes_at / _by : frais d'inscription et
 *   péri-académiques réglés, cochés par un agent. Distincts de `frais_paye`
 *   (frais de dossier de candidature). Conditionnent la mise à disposition de
 *   la lettre d'admission dans l'espace de l'auditeur.
 * - campagnes_candidature.parametres_lettres : variables des lettres de la
 *   promotion (références, dates, rentrée, frais), saisies dans l'admin.
 * - campagnes_candidature.signature_lettres_path : image de la signature et
 *   du cachet, sur le stockage privé.
 *
 * Strictement additif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidatures', function (Blueprint $table): void {
            $table->timestampTz('frais_inscription_payes_at')->nullable();
            $table->foreignId('frais_inscription_payes_by')->nullable()->index()->constrained('users')->nullOnDelete();
        });

        Schema::table('campagnes_candidature', function (Blueprint $table): void {
            $table->jsonb('parametres_lettres')->nullable();
            $table->string('signature_lettres_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('campagnes_candidature', function (Blueprint $table): void {
            $table->dropColumn(['parametres_lettres', 'signature_lettres_path']);
        });

        Schema::table('candidatures', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('frais_inscription_payes_by');
            $table->dropColumn('frais_inscription_payes_at');
        });
    }
};
