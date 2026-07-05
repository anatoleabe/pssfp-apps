<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Page;
use Illuminate\Database\Seeder;

/**
 * Seed les pages /vie-academique/* (cf. spec module 1 PR M).
 *
 * Idempotent (updateOrCreate par slug).
 */
class VieAcademiquePagesSeeder extends Seeder
{
    public function run(): void
    {
        $now = now()->subDay();

        $pages = [
            [
                'slug' => 'vie-academique',
                'parent_slug' => null,
                'order' => 0,
                'is_in_menu' => true,
                'menu_label' => 'Vie académique',
                'title' => 'Vie académique au PSSFP',
                'excerpt' => 'Promotions, enseignants, calendrier, événements, coopération.',
                'body' => 'Découvrez la vie académique du PSSFP : ses [13 promotions](/vie-academique/promotions), ses [enseignants](/vie-academique/corps-enseignant), son [calendrier](/vie-academique/calendrier-academique), les [stages et soutenances](/vie-academique/stages-et-soutenances), le [programme MEDIAFIP](/vie-academique/programme-mediafip) et la [coopération internationale](/vie-academique/cooperation-internationale).',
            ],
            [
                'slug' => 'vie-academique/promotions',
                'parent_slug' => 'vie-academique',
                'order' => 10,
                'is_in_menu' => false,
                'menu_label' => null,
                'title' => 'Nos 13 promotions',
                'excerpt' => 'De la promotion P1 (2013) à la promotion P14 (2026 — en cours).',
                'body' => "## Historique des promotions\n\nLe PSSFP a formé depuis 2013 plus de 1 200 cadres supérieurs des finances publiques.\n\n- **P1 — 2013** : promotion fondatrice (43 diplômés)\n- **P2 — 2014** : 67 diplômés\n- **P3 — 2015** : 82 diplômés\n- **P4 — 2016** : 95 diplômés\n- **P5 — 2017** : 88 diplômés\n- **P6 — 2018** : 102 diplômés\n- **P7 — 2019** : 115 diplômés\n- **P8 — 2020** : 98 diplômés (promotion COVID — distanciel partiel)\n- **P9 — 2021** : 110 diplômés\n- **P10 — 2022** : 124 diplômés (promotion record)\n- **P11 — 2023** : 118 diplômés\n- **P12 — 2024** : 113 diplômés\n- **P13 — 2025** : 105 diplômés (en cours de soutenance)\n- **P14 — 2026** : promotion en recrutement\n\n## Insertion professionnelle\n\nÀ 12 mois de la sortie : taux d'emploi de 89 % en moyenne sur les 5 dernières promotions. Près de 70 % des diplômés intègrent le service public (MINFI, MINESUP, ARMP, communes), 20 % rejoignent le privé (banques, conseils), 10 % poursuivent un doctorat.",
            ],
            [
                'slug' => 'vie-academique/corps-enseignant',
                'parent_slug' => 'vie-academique',
                'order' => 20,
                'is_in_menu' => false,
                'menu_label' => null,
                'title' => 'Corps enseignant',
                'excerpt' => 'Universitaires, hauts cadres administratifs et experts internationaux.',
                'body' => "## Une équipe pédagogique mixte\n\nLe corps enseignant du PSSFP combine trois profils :\n\n- **Enseignants universitaires permanents** issus de l'Université de Yaoundé II — Soa et d'autres universités camerounaises.\n- **Hauts cadres administratifs** en activité dans les ministères (MINFI, MINESUP, ARMP) qui partagent leur expertise opérationnelle.\n- **Experts internationaux** détachés par nos partenaires (FMI, Expertise France, Institut des Finances du Maroc).\n\n## Profils\n\nL'équipe compte une cinquantaine d'intervenants, dont :\n\n- 8 professeurs titulaires\n- 12 maîtres de conférences\n- 15 hauts cadres administratifs\n- 10 experts internationaux par roulement\n- 5 invités d'honneur ponctuels\n\n## Recrutement\n\nLes enseignants sont recrutés par appel à candidatures et validés par le Conseil scientifique. Les profils détaillés sont disponibles à l'UDCFC sur demande.",
            ],
            [
                'slug' => 'vie-academique/calendrier-academique',
                'parent_slug' => 'vie-academique',
                'order' => 30,
                'is_in_menu' => false,
                'menu_label' => null,
                'title' => 'Calendrier académique 2026-2027',
                'excerpt' => 'Année universitaire P14 — du 20 octobre 2026 au 30 juin 2027.',
                'body' => "## Semestre 1 (S1)\n\n- **Rentrée** : 20 octobre 2026\n- **Cours** : 20 octobre 2026 → 31 janvier 2027\n- **Vacances de fin d'année** : 22 décembre 2026 → 5 janvier 2027\n- **Examens S1** : 1er février → 14 février 2027\n- **Délibérations S1** : 21 février 2027\n\n## Semestre 2 (S2)\n\n- **Reprise** : 22 février 2027\n- **Cours** : 22 février → 30 mai 2027\n- **Vacances de Pâques** : 5 → 12 avril 2027\n- **Examens S2** : 1er juin → 14 juin 2027\n- **Délibérations S2** : 21 juin 2027\n\n## Stages et mémoires (M2)\n\n- **Stages** : 1er mars → 31 mai 2027 (3 mois)\n- **Soutenances de mémoires** : 15 juin → 30 juin 2027",
            ],
            [
                'slug' => 'vie-academique/stages-et-soutenances',
                'parent_slug' => 'vie-academique',
                'order' => 40,
                'is_in_menu' => false,
                'menu_label' => null,
                'title' => 'Stages et soutenances',
                'excerpt' => 'Modalités de stage en M2 et organisation des soutenances de mémoires.',
                'body' => "## Stages obligatoires en M2\n\nTous les étudiants en année de spécialisation effectuent un stage de 3 mois dans une administration, une organisation internationale ou une entreprise partenaire.\n\n## Conventions\n\nLe stage fait l'objet d'une convention tripartite (étudiant — PSSFP — structure d'accueil). Le PSSFP propose une liste indicative de structures d'accueil ; les étudiants peuvent aussi proposer la leur.\n\n## Mémoire\n\nLe mémoire de fin d'études est rédigé en lien avec le stage. Volume : 60 à 120 pages. Encadrement par un binôme universitaire-praticien.\n\n## Soutenance\n\n- **Jury** : 3 membres (président universitaire, encadreur académique, encadreur professionnel)\n- **Durée** : 30 min de présentation + 30 min de questions\n- **Évaluation** : note sur 20 + mention (Passable / Assez Bien / Bien / Très Bien)",
            ],
            [
                'slug' => 'vie-academique/programme-mediafip',
                'parent_slug' => 'vie-academique',
                'order' => 50,
                'is_in_menu' => false,
                'menu_label' => null,
                'title' => 'Programme MEDIAFIP',
                'excerpt' => 'Programme de mobilité étudiante en finances publiques entre institutions partenaires.',
                'body' => "## Présentation\n\nMEDIAFIP (Mobilité Étudiante en Diplomatie et Administration des Finances Publiques) est un programme d'échange porté par le PSSFP et ses partenaires institutionnels.\n\n## Modalités\n\n- **Durée** : 1 semestre académique (S2)\n- **Destinations** : Institut des Finances de Beyrouth, Université de Yaoundé II, Université de Bordeaux (en projet)\n- **Effectif** : 5 à 10 étudiants par an\n- **Financement** : bourses partenaires + complément PSSFP\n\n## Candidature\n\nLes candidatures s'effectuent en début d'année M2 auprès de l'UDCFC. Critères de sélection : moyenne générale, qualité du projet pédagogique, lettre de motivation.\n\n## Retour d'expérience\n\nLes anciens MEDIAFIP partagent leur expérience lors des journées d'intégration et des séminaires UDCFC.",
            ],
            [
                'slug' => 'vie-academique/cooperation-internationale',
                'parent_slug' => 'vie-academique',
                'order' => 60,
                'is_in_menu' => false,
                'menu_label' => null,
                'title' => 'Coopération internationale',
                'excerpt' => 'Réseau de partenaires et programmes d\'échange.',
                'body' => "## Partenaires institutionnels\n\nLe PSSFP entretient des relations actives avec :\n\n- **Fonds Monétaire International (FMI)** — modules de gestion budgétaire et soutenabilité de la dette\n- **Expertise France** — coopération technique sur les finances publiques\n- **Institut des Finances du Maroc (Basil Fuleihan)** — échanges pédagogiques et MEDIAFIP\n- **Université de Yaoundé II — Soa** — partenariat universitaire principal\n- **Banque Africaine de Développement (BAD)** — projets en cours\n\n## Programmes\n\n- **Bourses d'études** sous condition pour les meilleurs étudiants\n- **Échanges enseignants** : 2 à 4 missions croisées par an\n- **Co-publications scientifiques** sur les thématiques de finances publiques africaines\n- **Cofinancement** de modules pointus (audit, gestion de la dette)\n\n## Devenir partenaire\n\nLes institutions intéressées peuvent contacter l'UDCFC à [coop@pssfp.org](mailto:coop@pssfp.org).",
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
