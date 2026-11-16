<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 15m (spec de la réinscription des anciens, §8.3 ; Q2 tranchée le 16/11/2026 : le dossier ACMA fait foi) :
 * identifiant eCampus figé d'un dossier dont le matricule a été remplacé par son matricule ACMA. Le compte eCampus,
 * créé sous l'ancien matricule, n'est jamais modifié : l'identifiant de connexion reste l'ancien. Nul pour tout autre
 * dossier (l'identifiant est alors le matricule en minuscules).
 *
 * Strictement additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        Schema::table('candidatures', function (Blueprint $table): void {
            $table->string('identifiant_ecampus', 30)->nullable()->unique();
        });
    }

    public function down(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        if (DB::table('candidatures')->whereNotNull('identifiant_ecampus')->exists()) {
            throw new RuntimeException('Identifiants eCampus figés par une reprise de matricule ACMA : défaire ce lot couperait leur connexion.');
        }
        Schema::table('candidatures', function (Blueprint $table): void {
            $table->dropUnique(['identifiant_ecampus']);
            $table->dropColumn('identifiant_ecampus');
        });
    }
};
