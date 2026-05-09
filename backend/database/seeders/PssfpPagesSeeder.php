<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Page;
use Illuminate\Database\Seeder;

/**
 * Seed les 8 pages de la rubrique /pssfp/* (cf. spec module 1 PR K) avec
 * un contenu placeholder réaliste mais validé par Anatole pour la démo COPIL.
 *
 * Idempotent : updateOrCreate par slug — sûr de re-jouer sur prod sans casser
 * les éditions Filament en place.
 */
class PssfpPagesSeeder extends Seeder
{
    public function run(): void
    {
        $now = now()->subDay();

        $pages = [
            [
                'slug' => 'pssfp/presentation',
                'parent_slug' => 'pssfp',
                'order' => 10,
                'is_in_menu' => true,
                'menu_label' => 'Présentation',
                'title' => 'Présentation du PSSFP',
                'excerpt' => 'Mission, vision et valeurs du Programme Supérieur de Spécialisation en Finances Publiques.',
                'body' => "## Notre mission\n\nLe Programme Supérieur de Spécialisation en Finances Publiques (PSSFP) forme depuis plus de dix ans les cadres supérieurs de l'administration financière camerounaise et africaine.\n\n## Notre vision\n\nDevenir le centre d'excellence de référence en formation aux finances publiques pour l'Afrique centrale et au-delà.\n\n## Nos valeurs\n\n- **Excellence académique** — exigence et rigueur dans tous nos cursus.\n- **Service public** — sens de l'intérêt général et de l'éthique.\n- **Coopération** — partenariats internationaux pour ouvrir nos diplômés au monde.",
            ],
            [
                'slug' => 'pssfp/mot-president',
                'parent_slug' => 'pssfp',
                'order' => 20,
                'is_in_menu' => true,
                'menu_label' => 'Mot du Président',
                'title' => 'Mot du Président du Comité de Pilotage',
                'excerpt' => 'Mot du Pr. BASAHAG Achile, Président du Comité de Pilotage.',
                'body' => "Chers candidats, partenaires, anciens étudiants,\n\nLe PSSFP est plus qu'un programme de formation : c'est une école de l'engagement public. Nos diplômés servent les administrations financières du Cameroun, de la sous-région et de la communauté internationale, dans le respect des valeurs de probité et d'excellence.\n\nDepuis 2013, nous avons formé plus de 1 200 diplômés. La promotion 14 que nous accueillons en 2026 ouvre un nouveau chapitre, avec un cursus enrichi par nos partenariats avec le FMI, l'Expertise France, et l'Institut des Finances du Maroc.\n\n*Pr. BASAHAG Achile* — Président du Comité de Pilotage.",
            ],
            [
                'slug' => 'pssfp/gouvernance',
                'parent_slug' => 'pssfp',
                'order' => 30,
                'is_in_menu' => true,
                'menu_label' => 'Gouvernance',
                'title' => 'Gouvernance du PSSFP',
                'excerpt' => 'Comité de pilotage, conseil scientifique, équipe pédagogique.',
                'body' => "## Comité de pilotage (COPIL)\n\nLe COPIL est l'instance de gouvernance stratégique du PSSFP. Il regroupe les représentants des ministères de tutelle, de l'université, et des partenaires institutionnels.\n\n## Conseil scientifique\n\nValide les programmes pédagogiques et le recrutement des enseignants. Il est composé d'universitaires et de praticiens reconnus en finances publiques.\n\n## Équipe pédagogique\n\nEnseignants permanents et vacataires issus de l'Université de Yaoundé II, du MINFI, et d'institutions partenaires (FMI, Expertise France).",
            ],
            [
                'slug' => 'pssfp/organigramme',
                'parent_slug' => 'pssfp',
                'order' => 40,
                'is_in_menu' => true,
                'menu_label' => 'Organigramme',
                'title' => 'Organigramme et organisation',
                'excerpt' => 'Structure organisationnelle du PSSFP — direction, unités fonctionnelles, services support.',
                'body' => "## Direction\n\n- Directeur du programme\n- Directeur des études\n- Secrétaire général\n\n## Unités fonctionnelles\n\n- **UPASS** — Unité Pédagogique, Académique, Scientifique et Stages.\n- **UDCFC** — Unité de la Documentation, de la Coopération, et de la Formation Continue.\n- **UAAF** — Unité Administrative, Affaires Financières.\n- **USI** — Unité des Systèmes d'Information.\n\n## Services support\n\nBibliothèque, intendance, communication, secrétariat des étudiants.",
            ],
            [
                'slug' => 'pssfp/conventions',
                'parent_slug' => 'pssfp',
                'order' => 50,
                'is_in_menu' => true,
                'menu_label' => 'Conventions',
                'title' => 'Conventions et accords de coopération',
                'excerpt' => 'Liste des conventions actives avec institutions partenaires.',
                'body' => "Le PSSFP entretient des relations institutionnelles formalisées avec :\n\n- **Université de Yaoundé II — Soa** — convention tripartite 2013, renouvelée en 2024.\n- **Institut des Finances du Maroc (Basil Fuleihan)** — accord d'échange pédagogique.\n- **Expertise France** — modules de coopération technique.\n- **Fonds Monétaire International (FMI)** — partenariat sur la soutenabilité budgétaire.\n- **Assemblée Nationale du Cameroun** — accueil de cadres parlementaires en formation continue.\n\nLes textes des conventions sont consultables auprès de l'UDCFC.",
            ],
            [
                'slug' => 'pssfp/infrastructure',
                'parent_slug' => 'pssfp',
                'order' => 60,
                'is_in_menu' => true,
                'menu_label' => 'Infrastructure',
                'title' => 'Infrastructure et campus',
                'excerpt' => 'Campus de Messa, amphis, bibliothèque, salles informatiques.',
                'body' => "## Campus de Messa\n\nLe PSSFP est hébergé au Campus de Messa à Yaoundé. Le site dispose de :\n\n- 2 amphithéâtres (capacité 150 et 80 places).\n- 5 salles de cours équipées de vidéoprojecteurs.\n- 1 salle informatique avec 30 postes connectés.\n- 1 bibliothèque physique + accès distant à la bibliothèque virtuelle.\n- Une cafétéria et un parking.\n\n## Plateforme FOAD\n\nLes étudiants en distanciel accèdent aux cours via la plateforme Moodle [foad.pssfp.net](https://foad.pssfp.net), accessible 24h/24.",
            ],
            [
                'slug' => 'pssfp/partenaires',
                'parent_slug' => 'pssfp',
                'order' => 70,
                'is_in_menu' => true,
                'menu_label' => 'Partenaires',
                'title' => 'Nos partenaires institutionnels',
                'excerpt' => 'MINFI, MINESUP, UY2, Expertise France, FMI, Institut des Finances du Maroc, Assemblée Nationale CM.',
                'body' => "## Partenaires nationaux\n\n- **Ministère des Finances (MINFI)** — tutelle technique.\n- **Ministère de l'Enseignement Supérieur (MINESUP)** — tutelle académique.\n- **Université de Yaoundé II — Soa** — partenaire universitaire principal.\n- **Assemblée Nationale du Cameroun** — coopération sur la formation continue.\n\n## Partenaires internationaux\n\n- **Fonds Monétaire International (FMI)** — modules de soutenabilité budgétaire et gestion de la dette.\n- **Expertise France** — coopération technique financière et juridique.\n- **Institut des Finances du Maroc (Basil Fuleihan)** — échanges pédagogiques.\n\nUn échange entre étudiants et enseignants est organisé chaque année.",
            ],
            [
                'slug' => 'pssfp/conformite-cames',
                'parent_slug' => 'pssfp',
                'order' => 80,
                'is_in_menu' => true,
                'menu_label' => 'Conformité CAMES',
                'title' => 'Conformité aux exigences CAMES',
                'excerpt' => 'Tableau récapitulatif des 12 exigences du Conseil Africain et Malgache pour l\'Enseignement Supérieur.',
                'body' => "Le PSSFP s'inscrit dans le cadre de l'accréditation CAMES (Conseil Africain et Malgache pour l'Enseignement Supérieur), garante de la qualité académique au niveau régional.\n\nLe tableau des **12 exigences CAMES** est consultable en bas de cette page. Pour chaque exigence, un lien vers la page institutionnelle du PSSFP qui en démontre la satisfaction.\n\n*Cette page intègre un tableau spécial rendu côté frontend (cf. spec module 1 PR K).*",
            ],
        ];

        foreach ($pages as $data) {
            Page::query()->updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'parent_slug' => $data['parent_slug'],
                    'title' => ['fr' => $data['title']],
                    'excerpt' => ['fr' => $data['excerpt']],
                    'body' => ['fr' => $data['body']],
                    'meta_title' => ['fr' => $data['title'].' — PSSFP'],
                    'meta_description' => ['fr' => $data['excerpt']],
                    'status' => Page::STATUS_PUBLISHED,
                    'published_at' => $now,
                    'order' => $data['order'],
                    'is_in_menu' => $data['is_in_menu'],
                    'menu_label' => $data['is_in_menu']
                        ? ['fr' => $data['menu_label']]
                        : null,
                ],
            );
        }
    }
}
