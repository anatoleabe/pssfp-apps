<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CampagneCandidature;
use Illuminate\Database\Seeder;

/*
 * Campagne de démo (env local/dev uniquement) — P14026 ouverte.
 *
 * Permet aux développeurs/testeurs d'avoir une campagne ouverte
 * pour tester les flux de candidature en local.
 *
 * Idempotent : updateOrCreate sur slug.
 */
class DemoCampagneSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing', 'development'])) {
            return;
        }

        CampagneCandidature::updateOrCreate(
            ['slug' => 'p14-2026'],
            [
                'prefix_numero' => 'P14026-',
                'nom' => 'Promotion 14 — Campagne 2026',
                'promotion_numero' => 14,
                'opens_at' => '2026-06-01 00:00:00',
                'closes_at' => '2026-09-30 23:59:59',
                'results_at' => '2026-10-31 23:59:59',
                'status' => 'open',
                'max_voeux' => 1,
            ]
        );
    }
}
