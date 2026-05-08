<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/*
 * Référentiel départements du Cameroun (58 entrées + AUTRES).
 *
 * Source : docs/migration-candidature/seed-reference-data.md §4
 * Idempotent : upsert sur PK code.
 */
class DepartementsCamerounSeeder extends Seeder
{
    public function run(): void
    {
        $departements = [
            ['code' => 'AUTRES',           'nom' => 'Autres',           'chef_lieu' => null,          'region_code' => 'Z AUTRES'],
            ['code' => 'Bamboutos',        'nom' => 'Bamboutos',        'chef_lieu' => 'Mbouda',      'region_code' => 'OUEST'],
            ['code' => 'Bénoué',           'nom' => 'Bénoué',           'chef_lieu' => 'Garoua',      'region_code' => 'NORD'],
            ['code' => 'Boumba-et-Ngoko',  'nom' => 'Boumba-et-Ngoko',  'chef_lieu' => 'Yokadouma',   'region_code' => 'EST'],
            ['code' => 'Boyo',             'nom' => 'Boyo',             'chef_lieu' => 'Fundong',     'region_code' => 'NORD-OUEST'],
            ['code' => 'Bui',              'nom' => 'Bui',              'chef_lieu' => 'Kumbo',       'region_code' => 'NORD-OUEST'],
            ['code' => 'Diamaré',          'nom' => 'Diamaré',          'chef_lieu' => 'Maroua',      'region_code' => 'EXTREME-NORD'],
            ['code' => 'Dja-et-Lobo',      'nom' => 'Dja-et-Lobo',      'chef_lieu' => 'Sangmélima',  'region_code' => 'SUD'],
            ['code' => 'Djérem',           'nom' => 'Djérem',           'chef_lieu' => 'Tibati',      'region_code' => 'ADAMAOUA'],
            ['code' => 'Donga-Mantung',    'nom' => 'Donga-Mantung',    'chef_lieu' => 'Nkambé',      'region_code' => 'NORD-OUEST'],
            ['code' => 'Fako',             'nom' => 'Fako',             'chef_lieu' => 'Limbé',       'region_code' => 'SUD-OUEST'],
            ['code' => 'Faro',             'nom' => 'Faro',             'chef_lieu' => 'Poli',        'region_code' => 'NORD'],
            ['code' => 'Faro-et-Déo',      'nom' => 'Faro-et-Déo',      'chef_lieu' => 'Tignère',     'region_code' => 'ADAMAOUA'],
            ['code' => 'Haut-Nkam',        'nom' => 'Haut-Nkam',        'chef_lieu' => 'Bafang',      'region_code' => 'OUEST'],
            ['code' => 'Haut-Nyong',       'nom' => 'Haut-Nyong',       'chef_lieu' => 'Abong-Mbang', 'region_code' => 'EST'],
            ['code' => 'Haute-Sanaga',     'nom' => 'Haute-Sanaga',     'chef_lieu' => 'Nanga-Eboko', 'region_code' => 'CENTRE'],
            ['code' => 'Hauts-Plateaux',   'nom' => 'Hauts-Plateaux',   'chef_lieu' => 'Baham',       'region_code' => 'OUEST'],
            ['code' => 'Kadey',            'nom' => 'Kadey',            'chef_lieu' => 'Batouri',     'region_code' => 'EST'],
            ['code' => 'Koung-Khi',        'nom' => 'Koung-Khi',        'chef_lieu' => 'Bandjoun',    'region_code' => 'OUEST'],
            ['code' => 'Koupé-Manengouba', 'nom' => 'Koupé-Manengouba', 'chef_lieu' => 'Bangem',      'region_code' => 'SUD-OUEST'],
            ['code' => 'Lebialem',         'nom' => 'Lebialem',         'chef_lieu' => 'Menji',       'region_code' => 'SUD-OUEST'],
            ['code' => 'Lekié',            'nom' => 'Lekié',            'chef_lieu' => 'Monatélé',    'region_code' => 'CENTRE'],
            ['code' => 'Logone-et-Chari',  'nom' => 'Logone-et-Chari',  'chef_lieu' => 'Kousséri',    'region_code' => 'EXTREME-NORD'],
            ['code' => 'Lom-et-Djérem',    'nom' => 'Lom-et-Djérem',    'chef_lieu' => 'Bertoua',     'region_code' => 'EST'],
            ['code' => 'Manyu',            'nom' => 'Manyu',            'chef_lieu' => 'Mamfé',       'region_code' => 'SUD-OUEST'],
            ['code' => 'Mayo-Banyo',       'nom' => 'Mayo-Banyo',       'chef_lieu' => 'Banyo',       'region_code' => 'ADAMAOUA'],
            ['code' => 'Mayo-Danay',       'nom' => 'Mayo-Danay',       'chef_lieu' => 'Yagoua',      'region_code' => 'EXTREME-NORD'],
            ['code' => 'Mayo-Kani',        'nom' => 'Mayo-Kani',        'chef_lieu' => 'Kaélé',       'region_code' => 'EXTREME-NORD'],
            ['code' => 'Mayo-Louti',       'nom' => 'Mayo-Louti',       'chef_lieu' => 'Guider',      'region_code' => 'NORD'],
            ['code' => 'Mayo-Rey',         'nom' => 'Mayo-Rey',         'chef_lieu' => 'Tcholliré',   'region_code' => 'NORD'],
            ['code' => 'Mayo-Sava',        'nom' => 'Mayo-Sava',        'chef_lieu' => 'Mora',        'region_code' => 'EXTREME-NORD'],
            ['code' => 'Mayo-Tsanaga',     'nom' => 'Mayo-Tsanaga',     'chef_lieu' => 'Mokolo',      'region_code' => 'EXTREME-NORD'],
            ['code' => 'Mbam-et-Inoubou',  'nom' => 'Mbam-et-Inoubou',  'chef_lieu' => 'Bafia',       'region_code' => 'CENTRE'],
            ['code' => 'Mbam-et-Kim',      'nom' => 'Mbam-et-Kim',      'chef_lieu' => 'Ntui',        'region_code' => 'CENTRE'],
            ['code' => 'Mbéré',            'nom' => 'Mbéré',            'chef_lieu' => 'Meiganga',    'region_code' => 'ADAMAOUA'],
            ['code' => 'Méfou-et-Afamba',  'nom' => 'Méfou-et-Afamba',  'chef_lieu' => 'Mfou',        'region_code' => 'CENTRE'],
            ['code' => 'Méfou-et-Akono',   'nom' => 'Méfou-et-Akono',   'chef_lieu' => 'Ngoumou',     'region_code' => 'CENTRE'],
            ['code' => 'Meme',             'nom' => 'Meme',             'chef_lieu' => 'Kumba',       'region_code' => 'SUD-OUEST'],
            ['code' => 'Menchum',          'nom' => 'Menchum',          'chef_lieu' => 'Wum',         'region_code' => 'NORD-OUEST'],
            ['code' => 'Menoua',           'nom' => 'Menoua',           'chef_lieu' => 'Dschang',     'region_code' => 'OUEST'],
            ['code' => 'Mezam',            'nom' => 'Mezam',            'chef_lieu' => 'Bamenda',     'region_code' => 'NORD-OUEST'],
            ['code' => 'Mfoundi',          'nom' => 'Mfoundi',          'chef_lieu' => 'Yaoundé',     'region_code' => 'CENTRE'],
            ['code' => 'Mifi',             'nom' => 'Mifi',             'chef_lieu' => 'Bafoussam',   'region_code' => 'OUEST'],
            ['code' => 'Momo',             'nom' => 'Momo',             'chef_lieu' => 'Mbengwi',     'region_code' => 'NORD-OUEST'],
            ['code' => 'Moungo',           'nom' => 'Moungo',           'chef_lieu' => 'Nkongsamba',  'region_code' => 'LITTORAL'],
            ['code' => 'Mvila',            'nom' => 'Mvila',            'chef_lieu' => 'Ebolowa',     'region_code' => 'SUD'],
            ['code' => 'Ndé',              'nom' => 'Ndé',              'chef_lieu' => 'Bangangté',   'region_code' => 'OUEST'],
            ['code' => 'Ndian',            'nom' => 'Ndian',            'chef_lieu' => 'Mudemba',     'region_code' => 'SUD-OUEST'],
            ['code' => 'Ngo-Ketunjia',     'nom' => 'Ngo-Ketunjia',     'chef_lieu' => 'Ndop',        'region_code' => 'NORD-OUEST'],
            ['code' => 'Nkam',             'nom' => 'Nkam',             'chef_lieu' => 'Yabassi',     'region_code' => 'LITTORAL'],
            ['code' => 'Noun',             'nom' => 'Noun',             'chef_lieu' => 'Foumban',     'region_code' => 'OUEST'],
            ['code' => 'Nyong-et-Kellé',   'nom' => 'Nyong-et-Kellé',   'chef_lieu' => 'Éséka',       'region_code' => 'CENTRE'],
            ['code' => 'Nyong-et-Mfoumou', 'nom' => 'Nyong-et-Mfoumou', 'chef_lieu' => 'Akonolinga',  'region_code' => 'CENTRE'],
            ['code' => "Nyong-et-So'o",    'nom' => "Nyong-et-So'o",    'chef_lieu' => 'Mbalmayo',    'region_code' => 'CENTRE'],
            ['code' => 'Océan',            'nom' => 'Océan',            'chef_lieu' => 'Kribi',       'region_code' => 'SUD'],
            ['code' => 'Sanaga-maritime',  'nom' => 'Sanaga-Maritime',  'chef_lieu' => 'Édéa',        'region_code' => 'LITTORAL'],
            ['code' => 'Vallée-du-Ntem',   'nom' => 'Vallée-du-Ntem',   'chef_lieu' => 'Ambam',       'region_code' => 'SUD'],
            ['code' => 'Vina',             'nom' => 'Vina',             'chef_lieu' => 'Ngaoundéré',  'region_code' => 'ADAMAOUA'],
            ['code' => 'Wouri',            'nom' => 'Wouri',            'chef_lieu' => 'Douala',      'region_code' => 'LITTORAL'],
        ];

        $now = now();
        $rows = array_map(
            fn ($d) => array_merge($d, ['created_at' => $now, 'updated_at' => $now]),
            $departements
        );
        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('departements_cameroun')->upsert(
                $chunk,
                ['code'],
                ['nom', 'chef_lieu', 'region_code', 'updated_at']
            );
        }
    }
}
