<?php

declare(strict_types=1);

use App\Services\Scolarite\RepriseComptes;
use App\Services\Scolarite\VerificationComptes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/*
 * Reprise des versements existants dans le compte de l'auditeur (spec D1, §10 ; ADR-0013).
 *
 * Idempotente (un auditeur déjà imputé est ignoré), sans SMS, sans e-mail, sans reçu.
 * Vérifiée dans la même transaction : situation de chaque inscription identique à l'ancien
 * calcul, avance égale à l'ancien trop-perçu, versé = imputé + avance, effectifs des filtres
 * identiques, invariant d'avance. Au moindre écart, la migration s'annule en entier et nomme
 * les lignes par leur uuid.
 *
 * `down()` ne fait rien : les imputations de reprise disparaissent avec leur table, que le
 * `down()` de la migration 2026_12_01_100000 retire tant que le compte n'a pas servi.
 */
return new class extends Migration
{
    public function up(): void
    {
        $bilan = app(RepriseComptes::class)->executer();

        $verification = app(VerificationComptes::class);
        $equivalence = $verification->equivalence();
        $ecarts = [...$verification->ecarts(), ...$equivalence['ecarts']];
        if ($ecarts !== []) {
            throw new RuntimeException('Reprise des comptes annulée, '.count($ecarts).' écart(s) : '.implode(' ; ', array_slice($ecarts, 0, 50))
                .(count($ecarts) > 50 ? ' ; …' : ''));
        }

        Log::info('Reprise des comptes', $bilan + [
            'totaux' => $verification->totaux(),
            'inscriptions_comparees' => $equivalence['inscriptions'],
            'auditeurs_hors_equivalence' => $equivalence['exclus'],
        ]);
    }

    public function down(): void {}
};
