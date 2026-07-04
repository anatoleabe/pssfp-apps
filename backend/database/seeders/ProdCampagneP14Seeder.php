<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CampagneCandidature;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Campagne Promotion 14 en PRODUCTION (LOT B.1).
 *
 * Sans campagne ouverte, currentCampaign() renvoie null et personne ne peut
 * postuler — ce seeder est un prérequis absolu du go-live candidature.
 *
 * - Idempotent : updateOrCreate sur slug, rejouable à chaque déploiement.
 * - Les dates (ouverture / clôture / résultats) sont une décision de la
 *   direction : lues dans .env (CAMPAGNE_P14_*), refus explicite si absentes
 *   ou incohérentes — pas de valeur par défaut en production.
 */
final class ProdCampagneP14Seeder extends Seeder
{
    public function run(): void
    {
        $opensAt = (string) config('pssfp.campagne_p14.opens_at', '');
        $closesAt = (string) config('pssfp.campagne_p14.closes_at', '');
        $resultsAt = (string) config('pssfp.campagne_p14.results_at', '');

        if ($opensAt === '' || $closesAt === '' || $resultsAt === '') {
            throw new RuntimeException(
                'CAMPAGNE_P14_OPENS_AT, CAMPAGNE_P14_CLOSES_AT et CAMPAGNE_P14_RESULTS_AT '
                .'doivent être définis dans .env avant de seed la campagne de production.'
            );
        }

        $opens = Carbon::parse($opensAt);
        $closes = Carbon::parse($closesAt);
        $results = Carbon::parse($resultsAt);

        if ($closes->lessThanOrEqualTo($opens) || $results->lessThan($closes)) {
            throw new RuntimeException(
                'Dates campagne P14 incohérentes : exigé opens_at < closes_at <= results_at '
                ."(reçu : {$opens->toDateTimeString()} / {$closes->toDateTimeString()} / {$results->toDateTimeString()})."
            );
        }

        CampagneCandidature::updateOrCreate(
            ['slug' => 'p14-2026'],
            [
                'prefix_numero' => 'P14026-',
                'nom' => 'Promotion 14 — Campagne 2026',
                'promotion_numero' => 14,
                'opens_at' => $opens,
                'closes_at' => $closes,
                'results_at' => $results,
                'status' => 'open',
                'max_voeux' => 1,
            ]
        );
    }
}
