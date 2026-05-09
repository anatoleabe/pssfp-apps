<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Page;
use Illuminate\Database\Seeder;

/**
 * Seed les pages de la rubrique /formations/* (cf. spec module 1 PR L).
 *
 * Décision baked-in (cf. prompt Sprint S3) : contenu via Filament + table pages
 * (Option B). Pas de modèles dédiés Specialite/FormationContinue/Certification
 * pour V1 — extensible plus tard.
 *
 * Idempotent (updateOrCreate par slug).
 */
class FormationsPagesSeeder extends Seeder
{
    public function run(): void
    {
        $now = now()->subDay();

        $pages = [
            // Sommaire
            [
                'slug' => 'formations',
                'parent_slug' => null,
                'order' => 0,
                'is_in_menu' => true,
                'menu_label' => 'Formations',
                'title' => 'Formations supérieures du PSSFP',
                'excerpt' => 'Master en finances publiques, formations continues, certifications.',
                'body' => "Le PSSFP propose un cursus master en 5 spécialités, des formations continues pour les cadres en activité, et des certifications professionnelles.\n\n## Master en Finances Publiques\n\nProgramme de 2 ans (4 semestres) accessible aux titulaires d'une licence ou équivalent. 5 spécialités au choix après le tronc commun.\n\n## Formation continue\n\nModules courts (1 à 4 semaines) pour les cadres de l'administration souhaitant approfondir un domaine spécifique.\n\n## Certifications\n\nCertifications professionnelles reconnues sur des thématiques pointues (audit, marchés publics, gouvernance locale).",
            ],
            // Tronc commun
            [
                'slug' => 'formations/master',
                'parent_slug' => 'formations',
                'order' => 10,
                'is_in_menu' => false,
                'menu_label' => null,
                'title' => 'Master en Finances Publiques',
                'excerpt' => 'Cursus de 2 ans (4 semestres) — tronc commun puis spécialisation.',
                'body' => "## Architecture du master\n\nLe master se déroule sur 2 ans (4 semestres) et combine cours fondamentaux, séminaires d'application et stage de fin d'études.\n\n## Année 1 — Tronc commun\n\nDroit constitutionnel et administratif, économie publique, comptabilité publique, statistiques appliquées, anglais, méthodologie de la recherche.\n\n## Année 2 — Spécialisation\n\nApprofondissement dans une des 5 spécialités. Stage de 3 mois en administration ou organisation internationale partenaire. Mémoire de recherche soutenu en fin d'année.\n\n## Modalités\n\nPrésentiel à Yaoundé (Campus de Messa) ou distanciel via la plateforme FOAD. Régime à temps plein ou aménagé pour les fonctionnaires en activité.",
            ],
            [
                'slug' => 'formations/tronc-commun',
                'parent_slug' => 'formations',
                'order' => 15,
                'is_in_menu' => false,
                'menu_label' => null,
                'title' => 'Tronc commun (M1)',
                'excerpt' => 'Programme de la première année — 12 unités d\'enseignement, ~60 ECTS.',
                'body' => "## Semestre 1 (S1)\n\n- UE1 — Droit budgétaire et comptable (6 ECTS)\n- UE2 — Économie publique (6 ECTS)\n- UE3 — Théories de la gestion publique (4 ECTS)\n- UE4 — Méthodologie de la recherche (3 ECTS)\n- UE5 — Anglais des finances publiques (3 ECTS)\n- UE6 — Statistiques appliquées (4 ECTS)\n\n## Semestre 2 (S2)\n\n- UE7 — Marchés publics et contrats publics (5 ECTS)\n- UE8 — Comptabilité publique avancée (5 ECTS)\n- UE9 — Fiscalité (5 ECTS)\n- UE10 — Audit interne et contrôle de gestion (4 ECTS)\n- UE11 — Anglais II (3 ECTS)\n- UE12 — Séminaire d'application (3 ECTS)\n\n## Évaluation\n\nContrôle continu (40 %) + examen terminal (60 %). Compensation entre UEs sous conditions.",
            ],
            // Spécialités liste
            [
                'slug' => 'formations/specialites',
                'parent_slug' => 'formations',
                'order' => 20,
                'is_in_menu' => false,
                'menu_label' => null,
                'title' => '5 spécialités du Master',
                'excerpt' => 'Choix de spécialisation en M2 parmi 5 parcours.',
                'body' => "En seconde année (M2), les étudiants approfondissent une des 5 spécialités proposées :\n\n1. **[Économie publique et gestion publique](/formations/specialites/economie-publique-gestion-publique)**\n2. **[Fiscalité, finance, comptabilité publique](/formations/specialites/fiscalite-finance-comptabilite-publique)** — la spécialité phare\n3. **[Gouvernance territoriale et finances publiques locales](/formations/specialites/gouvernance-territoriale-finances-publiques-locales)**\n4. **[Marchés publics et partenariats public-privé](/formations/specialites/marches-publics-partenariats-public-prive)**\n5. **[Audit et contrôle des finances publiques](/formations/specialites/audit-controle-finances-publiques)**\n\nChaque spécialité comporte un cursus de 30 ECTS au S3, un mémoire de recherche encadré, et un stage de 3 mois.",
            ],
            // 5 spécialités
            [
                'slug' => 'formations/specialites/economie-publique-gestion-publique',
                'parent_slug' => 'formations/specialites',
                'order' => 21,
                'is_in_menu' => false,
                'menu_label' => null,
                'title' => 'Économie publique et gestion publique',
                'excerpt' => 'Spécialité orientée analyse économique appliquée à la décision publique.',
                'body' => "## Objectifs de la spécialité\n\nFormer des cadres capables d'analyser les politiques publiques sous l'angle économique et de mesurer leur impact macro et micro-économique.\n\n## Modules clés\n\n- Analyse coût-bénéfice des projets publics\n- Économie de la régulation et des biens publics\n- Politiques sectorielles (santé, éducation, infrastructures)\n- Évaluation des politiques publiques\n\n## Débouchés\n\n- Direction des études économiques (MINFI, MINEPAT)\n- Cellules d'évaluation des politiques publiques\n- Organisations internationales (Banque mondiale, BAD, UE)\n- Cabinets de conseil et think tanks",
            ],
            [
                'slug' => 'formations/specialites/fiscalite-finance-comptabilite-publique',
                'parent_slug' => 'formations/specialites',
                'order' => 22,
                'is_in_menu' => false,
                'menu_label' => null,
                'title' => 'Fiscalité, finance et comptabilité publique',
                'excerpt' => 'Spécialité phare du PSSFP — droit fiscal, comptabilité publique, ingénierie budgétaire.',
                'body' => "## Objectifs de la spécialité\n\nFormer les futurs cadres des Directions générales des Impôts, des Douanes et du Trésor — l'épine dorsale du système des finances publiques camerounais.\n\n## Modules clés\n\n- Droit fiscal et procédures fiscales\n- Comptabilité publique avancée (loi de finances, exécution)\n- Ingénierie budgétaire et programmation pluriannuelle\n- Gestion de la dette publique\n- Contrôle fiscal et lutte contre la fraude\n\n## Débouchés\n\n- DGI, DGD, DGTCFM (Cameroun)\n- Directions des finances dans les ministères sectoriels\n- Cabinets d'expertise comptable et fiscale\n- Organisations internationales (FMI, OCDE)",
            ],
            [
                'slug' => 'formations/specialites/gouvernance-territoriale-finances-publiques-locales',
                'parent_slug' => 'formations/specialites',
                'order' => 23,
                'is_in_menu' => false,
                'menu_label' => null,
                'title' => 'Gouvernance territoriale et finances publiques locales',
                'excerpt' => 'Décentralisation, finances locales, gouvernance des collectivités.',
                'body' => "## Objectifs de la spécialité\n\nFormer des cadres maîtrisant les enjeux de la décentralisation et capables de piloter les finances des collectivités territoriales.\n\n## Modules clés\n\n- Droit de la décentralisation\n- Finances des collectivités locales (recettes propres, transferts)\n- Planification territoriale\n- Gouvernance et participation citoyenne\n- Coopération décentralisée\n\n## Débouchés\n\n- MINDDEVEL\n- Communes et conseils régionaux\n- Programmes de coopération décentralisée\n- ONG et bailleurs internationaux sur la gouvernance locale",
            ],
            [
                'slug' => 'formations/specialites/marches-publics-partenariats-public-prive',
                'parent_slug' => 'formations/specialites',
                'order' => 24,
                'is_in_menu' => false,
                'menu_label' => null,
                'title' => 'Marchés publics et partenariats public-privé',
                'excerpt' => 'Procédures, négociation, contractualisation et suivi des grands projets.',
                'body' => "## Objectifs de la spécialité\n\nFormer des experts du cycle complet des marchés publics et des partenariats public-privé, du sourcing à l'exécution.\n\n## Modules clés\n\n- Code des marchés publics et procédures d'attribution\n- Montage juridique et financier des PPP\n- Évaluation préalable et value for money\n- Gestion des risques contractuels\n- Suivi-évaluation des grands projets\n\n## Débouchés\n\n- ARMP (Agence de Régulation des Marchés Publics)\n- Cellules PPP des ministères\n- Organisations internationales (Banque mondiale)\n- Bureaux d'études et cabinets de conseil",
            ],
            [
                'slug' => 'formations/specialites/audit-controle-finances-publiques',
                'parent_slug' => 'formations/specialites',
                'order' => 25,
                'is_in_menu' => false,
                'menu_label' => null,
                'title' => 'Audit et contrôle des finances publiques',
                'excerpt' => 'Cour des comptes, contrôle interne, audit de performance et de régularité.',
                'body' => "## Objectifs de la spécialité\n\nFormer les auditeurs et contrôleurs des finances publiques — Cour des comptes, IGE, IGF, audit interne ministériel.\n\n## Modules clés\n\n- Théorie et méthodologie de l'audit\n- Audit de performance vs audit de régularité\n- Contrôle interne et maîtrise des risques\n- Normes ISSAI / INTOSAI\n- Détection et investigation de la fraude\n\n## Débouchés\n\n- Chambre des Comptes / Cour des Comptes\n- Inspection Générale d'État (IGE)\n- Inspection Générale des Finances (IGF)\n- Contrôle interne des entreprises publiques\n- Cabinets d'audit international (Big Four)",
            ],
            // Formation continue (sommaire — détails via /continue/[slug])
            [
                'slug' => 'formations/continue',
                'parent_slug' => 'formations',
                'order' => 30,
                'is_in_menu' => false,
                'menu_label' => null,
                'title' => 'Formation continue',
                'excerpt' => 'Modules courts pour cadres en activité — 1 à 4 semaines.',
                'body' => "## Catalogue 2026\n\n- **Module FC-01** — Préparation aux concours administratifs en finances (3 semaines, présentiel)\n- **Module FC-02** — Comptabilité matières et patrimoniale (2 semaines)\n- **Module FC-03** — Gestion de projet d'investissement public (1 semaine intensive)\n- **Module FC-04** — Anti-corruption et éthique des finances publiques (1 semaine)\n- **Module FC-05** — Pilotage budgétaire par la performance (2 semaines)\n\n## Modalités\n\nFrais de scolarité variables selon module (50 000 à 350 000 FCFA). Pris en charge par l'employeur dans le cadre du plan de formation. Possibilité de prise en charge partielle pour les candidats individuels.\n\n## Inscription\n\nDossier à déposer à l'UDCFC, ou en ligne via [candidature.pssfp.net](https://candidature.pssfp.net) en sélectionnant la campagne « Formation continue 2026 ».",
            ],
            // Certifications
            [
                'slug' => 'formations/certifications',
                'parent_slug' => 'formations',
                'order' => 40,
                'is_in_menu' => false,
                'menu_label' => null,
                'title' => 'Certifications professionnelles',
                'excerpt' => 'Certifications courtes reconnues sur des thématiques pointues.',
                'body' => "## Certifications proposées\n\n- **CPAFP** — Certification Professionnelle d'Audit des Finances Publiques (4 semaines + examen)\n- **CMPC** — Certification en Marchés Publics du Cameroun (3 semaines + examen)\n- **CGTL** — Certification en Gestion Territoriale Locale (2 semaines + projet)\n\nLes examens se tiennent deux fois par an (juin et décembre).\n\n## Reconnaissance\n\nLes certifications sont reconnues par le MINFI et l'ARMP. Elles donnent droit à des bonifications dans la grille indiciaire des fonctionnaires de catégorie A.\n\n## Inscription\n\nVoir [/formations/admission](/formations/admission) pour les conditions générales.",
            ],
            // Admission
            [
                'slug' => 'formations/admission',
                'parent_slug' => 'formations',
                'order' => 50,
                'is_in_menu' => false,
                'menu_label' => null,
                'title' => 'Admission au PSSFP',
                'excerpt' => 'Conditions d\'admission, calendrier, dossier et frais.',
                'body' => "## Conditions d'éligibilité\n\n- Diplôme de licence (3 ans post-bac) ou équivalent dans une discipline pertinente (économie, droit, gestion, sciences politiques)\n- Pour les fonctionnaires : autorisation hiérarchique\n- Pour les candidats internationaux : équivalence de diplôme validée\n\n## Calendrier (campagne P14 / 2026)\n\n- **Ouverture des candidatures** : 15 juin 2026\n- **Clôture** : 15 août 2026\n- **Examens d'admission** : 1er-15 septembre 2026\n- **Résultats** : 30 septembre 2026\n- **Rentrée académique** : 20 octobre 2026\n\n## Dossier de candidature\n\n5 pièces requises : photo identité, CV, copie diplôme, lettre de motivation, relevés de notes. Le dossier se constitue 100 % en ligne sur [candidature.pssfp.net](https://candidature.pssfp.net).\n\n## Frais de candidature\n\n50 000 FCFA non-remboursables, payables en agence CREMINCAM (cf. [/formations/frais-de-scolarite](/formations/frais-de-scolarite)).",
            ],
            // Frais
            [
                'slug' => 'formations/frais-de-scolarite',
                'parent_slug' => 'formations',
                'order' => 60,
                'is_in_menu' => false,
                'menu_label' => null,
                'title' => 'Frais de scolarité',
                'excerpt' => 'Frais détaillés par cursus et modalités de paiement.',
                'body' => "## Master\n\n- **Frais de candidature** : 50 000 FCFA (à la soumission, non-remboursables)\n- **Frais de scolarité M1** : 750 000 FCFA / an\n- **Frais de scolarité M2** : 750 000 FCFA / an\n- **Mémoire et soutenance** : 100 000 FCFA (au M2)\n\n## Formation continue\n\nVariable selon module : de 50 000 à 350 000 FCFA. Cf. [/formations/continue](/formations/continue) pour le catalogue détaillé.\n\n## Certifications\n\n- CPAFP : 250 000 FCFA (formation + examen)\n- CMPC : 200 000 FCFA\n- CGTL : 180 000 FCFA\n\n## Modalités de paiement\n\nLes frais de candidature et de scolarité sont payables en agence **CREMINCAM** (références bancaires fournies au candidat). Le récépissé bancaire est à présenter à l'UAAF pour validation.\n\nÉchelonnement possible des frais de scolarité en 3 tranches sur l'année académique, sur demande motivée.",
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
