<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/*
 * Référentiel régions du Cameroun + 1 entrée « Z AUTRES » (étranger / hors classification).
 *
 * Quotas d'admission (cf. spec §M7) — somme = 0.98, les 2% restants vont implicitement
 * à « Z AUTRES » (candidats résidant à l'étranger).
 *
 * Source : docs/migration-candidature/seed-reference-data.md §3
 */
class RegionsCamerounSeeder extends Seeder
{
    public function run(): void
    {
        $regions = [
            ['code' => 'ADAMAOUA',     'nom' => 'Adamaoua',     'quota_admission' => 0.05, 'chef_lieu' => 'Ngaoundéré', 'order' => 1],
            ['code' => 'CENTRE',       'nom' => 'Centre',       'quota_admission' => 0.15, 'chef_lieu' => 'Yaoundé',    'order' => 2],
            ['code' => 'EST',          'nom' => 'Est',          'quota_admission' => 0.04, 'chef_lieu' => 'Bertoua',    'order' => 3],
            ['code' => 'EXTREME-NORD', 'nom' => 'Extrême-Nord', 'quota_admission' => 0.18, 'chef_lieu' => 'Maroua',     'order' => 4],
            ['code' => 'LITTORAL',     'nom' => 'Littoral',     'quota_admission' => 0.12, 'chef_lieu' => 'Douala',     'order' => 5],
            ['code' => 'NORD',         'nom' => 'Nord',         'quota_admission' => 0.07, 'chef_lieu' => 'Garoua',     'order' => 6],
            ['code' => 'NORD-OUEST',   'nom' => 'Nord-Ouest',   'quota_admission' => 0.12, 'chef_lieu' => 'Bamenda',    'order' => 7],
            ['code' => 'OUEST',        'nom' => 'Ouest',        'quota_admission' => 0.13, 'chef_lieu' => 'Bafoussam',  'order' => 8],
            ['code' => 'SUD',          'nom' => 'Sud',          'quota_admission' => 0.04, 'chef_lieu' => 'Ebolowa',    'order' => 9],
            ['code' => 'SUD-OUEST',    'nom' => 'Sud-Ouest',    'quota_admission' => 0.08, 'chef_lieu' => 'Buéa',       'order' => 10],
            ['code' => 'Z AUTRES',     'nom' => 'Autres',       'quota_admission' => null, 'chef_lieu' => null,         'order' => 99],
        ];

        $now = now();
        $rows = array_map(
            fn ($r) => array_merge($r, ['created_at' => $now, 'updated_at' => $now]),
            $regions
        );
        DB::table('regions_cameroun')->upsert(
            $rows,
            ['code'],
            ['nom', 'quota_admission', 'chef_lieu', 'order', 'updated_at']
        );
    }
}
