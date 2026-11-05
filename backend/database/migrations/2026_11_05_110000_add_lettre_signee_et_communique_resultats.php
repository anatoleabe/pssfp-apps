<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Lettre d'admission signée de chaque auditeur, et communiqué des résultats
 * de la campagne, distinct de celui de l'appel à candidature.
 *
 * Strictement additif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidatures', function (Blueprint $table): void {
            $table->string('lettre_admission_path', 255)->nullable();
            $table->timestampTz('lettre_admission_deposee_at')->nullable();
            $table->foreignId('lettre_admission_deposee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->index('lettre_admission_deposee_par');
        });

        Schema::table('campagnes_candidature', function (Blueprint $table): void {
            $table->string('communique_resultats_path', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('campagnes_candidature', function (Blueprint $table): void {
            $table->dropColumn('communique_resultats_path');
        });

        Schema::table('candidatures', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('lettre_admission_deposee_par');
            $table->dropColumn(['lettre_admission_path', 'lettre_admission_deposee_at']);
        });
    }
};
