<?php

declare(strict_types=1);

use App\Services\Scolarite\RepriseScolarite;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/*
 * Reprise de l'existant (spec module 4 §10) : barèmes de l'année, auditeurs,
 * cases « droits payés » converties en versements. Idempotente et sans SMS.
 *
 * `down()` ne fait rien : les données reprises disparaissent avec les tables
 * (migration 2026_11_06_100000), et les colonnes d'origine restent intactes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $bilan = app(RepriseScolarite::class)->executer();
        Log::info('Reprise de la scolarité', $bilan);
    }

    public function down(): void {}
};
