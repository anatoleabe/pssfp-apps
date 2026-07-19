<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Page;
use Illuminate\Database\Seeder;

/**
 * Seed les pages de la rubrique /formations/* (cf. spec sprint S5 PR X).
 *
 * Sprint S5 PR X — refonte sur la base du catalogue officiel PSSFP extrait
 * dans docs/sources/catalogue-formations-extracted.txt :
 *   - Master Professionnel : 5 spécialités avec slugs « metiers-* »
 *     conformes au catalogue (au lieu des anciens slugs descriptifs).
 *   - Tarifs corrigés : Master 1 185 000 FCFA/an (au lieu de 750 000).
 *   - Formation continue : 10 modules courts (au lieu de 5 placeholder)
 *     avec contenu détaillé et tarifs catalogue.
 *   - Nouvelle page Séminaires (voyages d'étude Maroc + France).
 *
 * Idempotent (updateOrCreate par slug). Les anciennes pages aux slugs
 * remplacés (formations/specialites/fiscalite-finance-comptabilite-publique
 * etc.) doivent être nettoyées manuellement par le CMS si besoin — V1 :
 * coexistence acceptée pendant la transition.
 */
class FormationsPagesSeeder extends Seeder
{
    public const FORMATION_CONTINUE_MODULES = [
        'loi-finances-circulaire-execution' => 'Comprendre la Loi de Finances et sa Circulaire d\'Exécution',
        'gestion-financiere-entreprises-etablissements-publics' => 'Gestion Financière au sein des Entreprises et Établissements Publics',
        'budget-programme-gar' => 'Budget Programme et Gestion Axée sur les Résultats (GAR)',
        'controle-gestion-sicoges' => 'Contrôle de Gestion et Mise en Place du SICOGES',
        'infractions-reglementation-financiere' => 'Infractions à la Réglementation Financière et Faute de Gestion',
        'cartographie-risques' => 'Cartographie des Risques',
        'decentralisation-finances-publiques-locales' => 'Décentralisation et Finances Publiques Locales',
        'mobilisation-financements-exterieurs' => 'Mobilisation et Gestion des Financements Extérieurs',
        'marches-publics' => 'Marchés Publics',
        'chaine-ppbs' => 'Chaîne Planification-Programmation-Budgétisation et Suivi-Évaluation (PPBS)',
    ];

    public const SPECIALITES = [
        'metiers-budgetaires' => 'Les Métiers Budgétaires',
        'metiers-gouvernance-territoriale-decentralisation' => 'Les Métiers de la Gouvernance Territoriale et de la Décentralisation',
        'metiers-commande-publique' => 'Les Métiers de la Commande Publique',
        'metiers-fiscalite-comptabilite' => 'Les Métiers de la Fiscalité et de la Comptabilité',
        'metiers-audit-controle' => 'Les Métiers d\'Audit et de Contrôle',
    ];

    public function run(): void
    {
        $now = now()->subDay();

        foreach ($this->pages() as $data) {
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

    /**
     * @return list<array{slug: string, parent_slug: ?string, order: int, is_in_menu: bool, menu_label: ?string, title: string, excerpt: string, body: string}>
     */
    private function pages(): array
    {
        $pages = [
            $this->indexPage(),
            $this->masterIndexPage(),
            $this->troncCommunPage(),
            $this->specialitesIndexPage(),
        ];

        $order = 21;
        foreach (self::SPECIALITES as $slug => $title) {
            $pages[] = $this->specialitePage($slug, $title, $order++);
        }

        $pages[] = $this->formationContinueIndexPage();

        $orderModule = 31;
        foreach (self::FORMATION_CONTINUE_MODULES as $slug => $title) {
            $pages[] = $this->formationContinueModulePage($slug, $title, $orderModule++);
        }

        $pages[] = $this->certificationsPage();
        $pages[] = $this->seminairesPage();
        $pages[] = $this->admissionPage();
        $pages[] = $this->fraisPage();

        return $pages;
    }

    /** @return array<string, mixed> */
    private function indexPage(): array
    {
        return [
            'slug' => 'formations',
            'parent_slug' => null,
            'order' => 0,
            'is_in_menu' => true,
            'menu_label' => 'Formations',
            'title' => 'Formations supérieures du PSSFP',
            'excerpt' => 'Master Professionnel en 5 spécialités, 10 modules de formation continue, certifications internationales, séminaires et FOAD.',
            'body' => "Le PSSFP propose une offre de formation complète au service des acteurs publics camerounais et africains :\n\n## Master Professionnel en Finances Publiques\n\nCursus de 2 ans (4 semestres) accessible aux titulaires d'une licence (BAC+3) en économie, gestion ou droit. **5 spécialités** au choix après le tronc commun. Coût : **1 185 000 FCFA/an** (M1 et M2).\n\n[Découvrir le Master →](/formations/master)\n\n## Formation continue\n\n**10 modules courts** (3 à 5 jours) pour les administrations, élus locaux et cadres en activité souhaitant approfondir un domaine spécifique des finances publiques.\n\n[Voir le catalogue Formation continue →](/formations/formation-continue)\n\n## Certifications internationales\n\nCertifications en partenariat avec le FMI, l'Expertise France et l'Institut des Finances du Maroc.\n\n[Découvrir les certifications →](/formations/certifications)\n\n## Séminaires et voyages d'étude\n\nVoyages d'étude au Maroc et en France intégrés au cursus pédagogique.\n\n[Voir les séminaires →](/formations/seminaires)",
        ];
    }

    /** @return array<string, mixed> */
    private function masterIndexPage(): array
    {
        return [
            'slug' => 'formations/master',
            'parent_slug' => 'formations',
            'order' => 10,
            'is_in_menu' => true,
            'menu_label' => 'Master Professionnel',
            'title' => 'Master Professionnel en Finances Publiques',
            'excerpt' => 'Cursus BAC+5 sur 2 ans (4 semestres) — 1 185 000 FCFA/an. 5 spécialités. Reconnu CAMES.',
            'body' => "## Architecture du Master\n\nLe Master Professionnel en Finances Publiques se déroule sur **2 ans (4 semestres)** :\n\n- **Master 1 (M1)** — Tronc commun : économie publique, droit budgétaire, comptabilité publique, fiscalité, statistiques, méthodologie.\n- **Master 2 (M2)** — Spécialisation au choix parmi 5 parcours + mémoire de recherche + stage de 3 mois.\n\n## Conditions d'admission\n\n- Diplôme universitaire de niveau **BAC+3** en économie, gestion, droit ou discipline assimilée.\n- Justifier d'une expérience d'au moins **5 ans** dans un service ou une structure lié aux Finances Publiques (VAE possible selon cohérence formation/projet professionnel).\n\n[Voir toutes les conditions d'admission →](/formations/admission)\n\n## Tarifs\n\n| Cursus | Coût |\n|---|---|\n| **Master 1 (M1)** | 1 185 000 FCFA |\n| **Master 2 (M2)** | 1 135 000 FCFA |\n| **Total cycle** | **2 320 000 FCFA** |\n\nFrais de candidature additionnels : 50 000 FCFA (cf. [Frais de scolarité](/formations/frais-de-scolarite)).\n\n## Modalités\n\n- **Présentiel** au Campus de Messa (Yaoundé).\n- **Distanciel** via la plateforme [FOAD](https://foad.pssfp.org).\n- **Hybride** possible selon profil de l'apprenant.\n\n## Les 5 spécialités\n\n1. [Les Métiers Budgétaires](/formations/specialites/metiers-budgetaires)\n2. [Les Métiers de la Gouvernance Territoriale et de la Décentralisation](/formations/specialites/metiers-gouvernance-territoriale-decentralisation)\n3. [Les Métiers de la Commande Publique](/formations/specialites/metiers-commande-publique)\n4. [Les Métiers de la Fiscalité et de la Comptabilité](/formations/specialites/metiers-fiscalite-comptabilite)\n5. [Les Métiers d'Audit et de Contrôle](/formations/specialites/metiers-audit-controle)\n\n## Reconnaissance\n\nDiplôme **reconnu CAMES** (Conseil Africain et Malgache pour l'Enseignement Supérieur). Inséré dans la grille indiciaire des fonctionnaires de catégorie A au Cameroun.\n\n[Voir les conditions d'admission complètes →](/formations/admission)",
        ];
    }

    /** @return array<string, mixed> */
    private function troncCommunPage(): array
    {
        return [
            'slug' => 'formations/tronc-commun',
            'parent_slug' => 'formations',
            'order' => 15,
            'is_in_menu' => false,
            'menu_label' => null,
            'title' => 'Tronc commun (M1)',
            'excerpt' => 'Programme de la première année — 12 unités d\'enseignement, ~60 ECTS.',
            'body' => "## Semestre 1 (S1)\n\n- UE1 — Droit budgétaire et comptable (6 ECTS)\n- UE2 — Économie publique (6 ECTS)\n- UE3 — Théories de la gestion publique (4 ECTS)\n- UE4 — Méthodologie de la recherche (3 ECTS)\n- UE5 — Anglais des finances publiques (3 ECTS)\n- UE6 — Statistiques appliquées (4 ECTS)\n\n## Semestre 2 (S2)\n\n- UE7 — Marchés publics et contrats publics (5 ECTS)\n- UE8 — Comptabilité publique avancée (5 ECTS)\n- UE9 — Fiscalité (5 ECTS)\n- UE10 — Audit interne et contrôle de gestion (4 ECTS)\n- UE11 — Anglais II (3 ECTS)\n- UE12 — Séminaire d'application (3 ECTS)\n\n## Évaluation\n\nContrôle continu (40 %) + examen terminal (60 %). Compensation entre UEs sous conditions.",
        ];
    }

    /** @return array<string, mixed> */
    private function specialitesIndexPage(): array
    {
        return [
            'slug' => 'formations/specialites',
            'parent_slug' => 'formations',
            'order' => 20,
            'is_in_menu' => false,
            'menu_label' => null,
            'title' => '5 spécialités du Master Professionnel',
            'excerpt' => 'Choix de spécialisation en M2 parmi 5 parcours alignés sur les besoins de l\'administration publique camerounaise.',
            'body' => "En seconde année (M2), les étudiants approfondissent une des **5 spécialités** proposées :\n\n1. **[Les Métiers Budgétaires](/formations/specialites/metiers-budgetaires)** — vue d'ensemble de l'intervention publique dans l'économie\n2. **[Les Métiers de la Gouvernance Territoriale et de la Décentralisation](/formations/specialites/metiers-gouvernance-territoriale-decentralisation)** — fonctions de cadres dans les collectivités locales\n3. **[Les Métiers de la Commande Publique](/formations/specialites/metiers-commande-publique)** — experts en marchés publics et PPP\n4. **[Les Métiers de la Fiscalité et de la Comptabilité](/formations/specialites/metiers-fiscalite-comptabilite)** — DGI, DGD, DGTCFM\n5. **[Les Métiers d'Audit et de Contrôle](/formations/specialites/metiers-audit-controle)** — Cour des comptes, IGE, IGF\n\nChaque spécialité comporte un cursus M2 de 30 ECTS, un mémoire de recherche encadré, et un stage de 3 mois.",
        ];
    }

    /** @return array<string, mixed> */
    private function specialitePage(string $slug, string $title, int $order): array
    {
        $contents = $this->specialiteContents();
        $content = $contents[$slug];

        return [
            'slug' => 'formations/specialites/'.$slug,
            'parent_slug' => 'formations/specialites',
            'order' => $order,
            'is_in_menu' => false,
            'menu_label' => null,
            'title' => $title,
            'excerpt' => $content['excerpt'],
            'body' => $content['body'],
        ];
    }

    /**
     * @return array<string, array{excerpt: string, body: string}>
     */
    private function specialiteContents(): array
    {
        return [
            'metiers-budgetaires' => [
                'excerpt' => 'Vue d\'ensemble de l\'intervention publique dans l\'économie. Préparation aux fonctions d\'analyse et de gestion budgétaire.',
                'body' => "## Description\n\nDonner une vue d'ensemble de l'intervention publique dans l'économie avec pour but son développement, d'en permettre une analyse et de cerner les contours de la gestion publique à travers des mécanismes précis et les instruments de gestion existants.\n\n## Objectifs spécifiques\n\n- Analyser les politiques publiques\n- Utiliser les cadres théoriques et conceptuels de façon rigoureuse\n- Fournir les connaissances opérationnelles pour adresser les problématiques d'inégalités internes et de redistribution des richesses entre tous les secteurs d'activités\n\n## Cibles de la formation\n\n- Titulaires d'une licence en économie, gestion ou droit\n- Salariés et fonctionnaires en activité\n- Demandeurs d'emploi qualifiés\n\n## Coût et durée\n\n- **Coût** : 1 185 000 FCFA / an (Master 1 et Master 2)\n- **Durée** : 2 ans (4 semestres)\n- **Lieu** : Campus de Messa, Yaoundé\n- **Modalité** : présentiel ou distanciel (FOAD)\n\n## Quelques unités d'enseignement dispensées\n\n- Budget programme\n- État et secteur public\n- Élaboration des politiques publiques\n- Conjoncture et prévision économique\n- Politique économique\n- Comptabilité publique\n- Cadrage macroéconomique\n- Contrôle de gestion des politiques publiques\n\n## Débouchés\n\n- Direction des études économiques (MINFI, MINEPAT)\n- Cellules d'évaluation des politiques publiques\n- Organisations internationales (Banque mondiale, BAD, UE)\n- Cabinets de conseil et think tanks",
            ],
            'metiers-gouvernance-territoriale-decentralisation' => [
                'excerpt' => 'Liens entre développement local et gouvernance. Préparation aux fonctions de cadres dans les CTD.',
                'body' => "## Description\n\nConnaître les liens entre le développement local et la gouvernance, préparer aux fonctions de cadres administratifs et financiers dans les collectivités locales ainsi que dans les organismes publics ou privés qui entretiennent des relations avec elles.\n\n## Objectifs spécifiques\n\n- Maîtriser les règles et procédures applicables à la fonction publique territoriale\n- Analyser et synthétiser des documents de nature juridique, ainsi qu'utiliser les techniques de veille réglementaire\n- Mettre en œuvre les outils de développement local\n\n## Cibles de la formation\n\n- Cadres des Collectivités Territoriales Décentralisées (CTD)\n- Fonctionnaires du MINDDEVEL\n- Responsables d'organismes en lien avec les CTD\n- Étudiants en gestion publique souhaitant se spécialiser sur la décentralisation\n\n## Coût et durée\n\n- **Coût** : 1 185 000 FCFA / an\n- **Durée** : 2 ans\n- **Lieu** : Campus de Messa, Yaoundé\n\n## Quelques unités d'enseignement dispensées\n\n- Audit et contrôle des CTD\n- Analyse financière des CTD\n- Comptabilité de l'ordonnateur d'une CTD\n- Comptabilité générale des CTD\n- Cadre stratégique du développement local\n- Décentralisation et gestion locale\n- Économie locale et action fiscale locale\n- Gestion du patrimoine local\n- Politique budgétaire\n\n## Débouchés\n\n- Communes et conseils régionaux\n- MINDDEVEL et FEICOM\n- Programmes de coopération décentralisée\n- ONG et bailleurs internationaux sur la gouvernance locale",
            ],
            'metiers-commande-publique' => [
                'excerpt' => 'Experts spécialisés dans le domaine des marchés publics, des délégations de service public et des PPP.',
                'body' => "## Description\n\nFormer les experts spécialisés dans le domaine des marchés publics, des délégations de service public et de contrats de partenariat dont les enjeux sont importants.\n\n## Objectifs spécifiques\n\n- Maîtriser les cadres juridiques des marchés publics et des contrats de partenariats\n- Comprendre les sources du droit de la commande publique\n- Maîtriser les procédures de passation et d'exécution des marchés publics\n\n## Cibles de la formation\n\n- Cadres de l'ARMP (Agence de Régulation des Marchés Publics)\n- Responsables des cellules PPP des ministères\n- Juristes spécialisés en droit public\n- Acteurs des grands projets d'infrastructure\n\n## Coût et durée\n\n- **Coût** : 1 185 000 FCFA / an\n- **Durée** : 2 ans\n- **Lieu** : Campus de Messa, Yaoundé\n\n## Quelques unités d'enseignement dispensées\n\n- Analyse économique\n- Exécution des contrats et négociation\n- Programmation et budgétisation des marchés publics\n- Contrôle, sanction et contentieux des marchés publics\n- Sûretés des marchés publics\n- Régulation des marchés publics\n- Technique d'élaboration des documents de passation des contrats\n- Négociation des partenariats publics-privés\n- Analyse économique des partenariats publics-privés\n\n## Débouchés\n\n- ARMP\n- Cellules PPP des ministères et grands établissements publics\n- Cabinets de conseil en marchés publics\n- Organisations internationales (Banque mondiale)",
            ],
            'metiers-fiscalite-comptabilite' => [
                'excerpt' => 'Spécialité orientée DGI, DGD, DGTCFM — droit fiscal, comptabilité publique, ingénierie budgétaire.',
                'body' => "## Description\n\nDynamiser les professionnels de la finance et de la comptabilité publique, répondant à une demande de plus en plus forte exprimée par des économies compétitives et mondialisées.\n\n## Objectifs spécifiques\n\n- Comprendre les mécanismes des principaux impôts et maîtriser les concepts de taxe propres à chaque type d'impôts\n- Maîtriser les procédures fiscales et douanières\n- Maîtriser les règles fondamentales du droit financier et de la comptabilité publique\n\n## Cibles de la formation\n\n- Futurs cadres de la DGI, DGD, DGTCFM\n- Comptables publics\n- Auditeurs fiscaux\n- Étudiants se destinant aux carrières de la comptabilité publique\n\n## Coût et durée\n\n- **Coût** : 1 185 000 FCFA / an\n- **Durée** : 2 ans\n- **Lieu** : Campus de Messa, Yaoundé\n\n## Quelques unités d'enseignement dispensées\n\n- Fiscalité des ressources naturelles\n- Fiscalité internationale / grands problèmes fiscaux\n- Fiscalité des entreprises\n- Régime douanier\n- Comptabilité publique\n- Cadre comptable de la CEMAC\n- Mécanismes de financement de l'économie\n- Procédures de dédouanement\n- Contentieux fiscal\n- Institutions financières internationales\n\n## Débouchés\n\n- Direction Générale des Impôts (DGI)\n- Direction Générale des Douanes (DGD)\n- Direction Générale du Trésor (DGTCFM)\n- Cabinets d'expertise comptable et fiscale\n- Organisations internationales (FMI, OCDE)",
            ],
            'metiers-audit-controle' => [
                'excerpt' => 'Auditeurs et contrôleurs : Cour des comptes, IGE, IGF, audit interne ministériel.',
                'body' => "## Description\n\nMaîtriser les risques de l'audit et du contrôle de gestion ainsi que l'évolution des normes comptables dans un contexte où la qualité de l'information financière, la transparence des comptes publics et le management des risques constituent des enjeux importants.\n\n## Objectifs spécifiques\n\n- Analyser les données budgétaires et comptables\n- Maîtriser les fondamentaux en finance et comptabilité pour manager les fonctions d'audit et de contrôle de gestion\n- Contrôler et évaluer les procédures de gestion\n\n## Cibles de la formation\n\n- Futurs auditeurs de la Chambre des Comptes / Cour des Comptes\n- Cadres de l'IGE et de l'IGF\n- Contrôleurs internes des entreprises publiques\n- Étudiants se destinant aux carrières de l'audit\n\n## Coût et durée\n\n- **Coût** : 1 185 000 FCFA / an\n- **Durée** : 2 ans\n- **Lieu** : Campus de Messa, Yaoundé\n\n## Quelques unités d'enseignement dispensées\n\n- Certification du compte général de l'État\n- Procédures de recettes et de dépenses publiques\n- Contrôle et audit des finances publiques, jugement des comptes (contrôle juridictionnel)\n- Procédures et contrôle de l'administration publique (contrôle administratif des finances publiques)\n- Contrôle et audit des systèmes d'information\n- Audit et contrôle des marchés publics\n- Analyse financière\n- Contrôle de performance\n\n## Débouchés\n\n- Chambre des Comptes / Cour des Comptes\n- Inspection Générale d'État (IGE)\n- Inspection Générale des Finances (IGF)\n- Contrôle interne des entreprises publiques\n- Cabinets d'audit international (Big Four)",
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function formationContinueIndexPage(): array
    {
        $modulesList = '';
        $i = 1;
        foreach (self::FORMATION_CONTINUE_MODULES as $slug => $title) {
            $modulesList .= sprintf("%d. **[%s](/formations/formation-continue/%s)**\n", $i++, $title, $slug);
        }

        return [
            'slug' => 'formations/formation-continue',
            'parent_slug' => 'formations',
            'order' => 30,
            'is_in_menu' => true,
            'menu_label' => 'Formation continue',
            'title' => 'Formation continue PSSFP',
            'excerpt' => '10 modules courts (3 à 5 jours) pour les administrations, élus locaux et cadres en activité.',
            'body' => "## Pour qui ?\n\nLa formation continue du PSSFP s'adresse à :\n\n- **Acteurs financiers** des secteurs public et privé\n- **Responsables des Collectivités Territoriales Décentralisées (CTD)**\n- **Élus locaux**\n- **Cadres et professionnels** souhaitant approfondir un domaine des finances publiques\n\nCe canal de renforcement des capacités permet d'outiller les responsables et cadres d'administrations dans des domaines professionnels spécifiques nécessaires pour améliorer leur mode de gestion des finances publiques.\n\n## Tarifs\n\n| Public | Tarif |\n|---|---|\n| **Administrations** (session < 15 personnes) | **4 995 000 FCFA** |\n| **Individus** | **500 000 FCFA / personne** |\n| **Étudiants du PSSFP** | **250 000 FCFA / personne** |\n\n**Durée** : 3 à 5 jours selon le module.\n\nLes formations peuvent être **qualifiantes** (savoir-faire et savoir-orienté) ou **certifiantes** (certificat ou attestation de participation en plus des qualifications acquises).\n\n## Catalogue 2026 — 10 modules\n\n{$modulesList}\n\n## Demander un devis\n\nPour une demande de devis pour votre administration ou organisation, contactez-nous à [usi@pssfp.org](mailto:usi@pssfp.org) en précisant :\n\n- Nom de l'organisation\n- Module(s) souhaité(s)\n- Nombre de participants estimé\n- Période(s) souhaitée(s)\n\n[📄 Télécharger le catalogue complet (PDF)](/documents/catalogue-pssfp-2026.pdf)",
        ];
    }

    /** @return array<string, mixed> */
    private function formationContinueModulePage(string $slug, string $title, int $order): array
    {
        $contents = $this->formationContinueModuleContents();
        $content = $contents[$slug] ?? [
            'excerpt' => $title,
            'body' => $title,
        ];

        return [
            'slug' => 'formations/formation-continue/'.$slug,
            'parent_slug' => 'formations/formation-continue',
            'order' => $order,
            'is_in_menu' => false,
            'menu_label' => null,
            'title' => $title,
            'excerpt' => $content['excerpt'],
            'body' => $content['body'],
        ];
    }

    /**
     * Contenu détaillé des 10 modules de formation continue extrait du
     * catalogue officiel (cf. docs/sources/catalogue-formations-extracted.txt).
     *
     * @return array<string, array{excerpt: string, body: string}>
     */
    private function formationContinueModuleContents(): array
    {
        $tariffs = "## Tarifs\n\n- **Administrations** (session < 15 personnes) : 4 995 000 FCFA\n- **Individus** : 500 000 FCFA / personne\n- **Étudiants du PSSFP** : 250 000 FCFA / personne\n\n**Durée** : 3 à 5 jours.\n\n[Demander un devis →](mailto:usi@pssfp.org?subject=Demande de devis Formation continue)";

        return [
            'loi-finances-circulaire-execution' => [
                'excerpt' => 'Sensibilisation aux innovations législatives et aux responsabilités des acteurs financiers.',
                'body' => "## Objectif\n\nOutiller les administrations et structures publiques sur les innovations contenues dans la loi de finances annuelle, ainsi que sur ses implications pratiques. Aider les gestionnaires à mieux appréhender les changements réglementaires et à adapter leurs pratiques en conséquence pour assurer une gestion efficace des crédits publics.\n\n## Public cible\n\n- Cadres des administrations publiques\n- Gestionnaires de crédits\n- Responsables d'unités budgétaires\n\n## Compétences acquises\n\n- Lecture et interprétation de la loi de finances\n- Application de la circulaire d'exécution budgétaire\n- Identification des risques réglementaires\n\n{$tariffs}",
            ],
            'gestion-financiere-entreprises-etablissements-publics' => [
                'excerpt' => 'Gouvernance financière moderne des établissements publics — application des lois de 2017.',
                'body' => "## Objectif\n\nAxée sur les **lois N° 2017/010 et 2017/011 du 12 juillet 2017**, cette formation apporte des éclairages sur la gouvernance financière moderne des établissements publics. Elle permet de maîtriser les rôles des différents acteurs, leurs responsabilités et les exigences de performance dans le cadre du fonctionnement des entreprises et établissements publics.\n\n## Public cible\n\n- Dirigeants d'entreprises et établissements publics\n- Membres des conseils d'administration\n- Cadres financiers des EPA et EPIC\n\n## Compétences acquises\n\n- Application des lois 2017/010 et 011\n- Gestion budgétaire et patrimoniale des établissements publics\n- Reporting de performance\n\n{$tariffs}",
            ],
            'budget-programme-gar' => [
                'excerpt' => 'Budgétisation par programmes, logique de performance, élaboration CBMT/CDMT/PAP/RAP.',
                'body' => "## Objectif\n\nDestinée aux acteurs du secteur public, cette formation met l'accent sur la **budgétisation par programmes**, la **logique de performance**, et l'élaboration de documents budgétaires (**CBMT**, **CDMT**, **PAP**, **RAP**). Elle vise à renforcer les compétences pour une gestion publique orientée vers les résultats.\n\n## Public cible\n\n- Responsables de programmes budgétaires\n- Cadres en charge de la programmation budgétaire\n- Contrôleurs de gestion publics\n\n## Compétences acquises\n\n- Élaboration d'un Cadre Budgétaire à Moyen Terme (CBMT)\n- Construction d'un Cadre de Dépenses à Moyen Terme (CDMT)\n- Rédaction de Projets Annuels de Performance (PAP)\n- Préparation de Rapports Annuels de Performance (RAP)\n\n{$tariffs}",
            ],
            'controle-gestion-sicoges' => [
                'excerpt' => 'Maîtriser les outils du contrôle de gestion dans le contexte du budget programme + logiciel SICOGES.',
                'body' => "## Objectif\n\nCette formation permet de maîtriser les outils du contrôle de gestion dans le contexte du budget programme. Elle introduit le **logiciel SICOGES** développé par le PSSFP, destiné à optimiser le suivi de la performance administrative via des tableaux de bord et un reporting efficace.\n\n## Public cible\n\n- Contrôleurs de gestion publics\n- Cadres en charge du pilotage de la performance\n- Responsables des systèmes d'information de gestion\n\n## Compétences acquises\n\n- Définition d'indicateurs de performance pertinents\n- Construction de tableaux de bord de pilotage\n- Utilisation du SICOGES pour le reporting\n- Analyse des écarts et propositions d'actions correctives\n\n{$tariffs}",
            ],
            'infractions-reglementation-financiere' => [
                'excerpt' => 'Sensibilisation aux risques de responsabilité administrative, civile ou pénale dans la gestion publique.',
                'body' => "## Objectif\n\nSensibiliser les gestionnaires de crédits publics aux risques de **responsabilité administrative, civile ou pénale**. L'objectif est d'éviter les fautes de gestion à travers l'acquisition de bonnes pratiques, notamment par l'audit interne et le contrôle de gestion.\n\n## Public cible\n\n- Ordonnateurs de crédits\n- Comptables publics\n- Auditeurs internes\n- Membres des juridictions financières\n\n## Compétences acquises\n\n- Identification des infractions à la réglementation financière\n- Cartographie des risques de gestion\n- Mise en place de dispositifs de prévention\n- Coopération avec les organes de contrôle\n\n{$tariffs}",
            ],
            'cartographie-risques' => [
                'excerpt' => 'Établir une cartographie complète des risques et renforcer la capacité à anticiper, gérer et surveiller les menaces.',
                'body' => "## Objectif\n\nCette formation permet aux responsables d'établir une **cartographie complète des risques** et de renforcer leur capacité à anticiper, gérer et surveiller les menaces potentielles. Elle relie la gestion des risques au contrôle interne et à l'audit.\n\n## Public cible\n\n- Auditeurs internes\n- Risk managers\n- Cadres en charge du contrôle interne\n- Responsables qualité\n\n## Compétences acquises\n\n- Méthodologies de cartographie des risques (méthode AMDEC, ISO 31000)\n- Évaluation de l'impact et de la probabilité\n- Définition de plans de mitigation\n- Suivi et révision périodique de la cartographie\n\n{$tariffs}",
            ],
            'decentralisation-finances-publiques-locales' => [
                'excerpt' => 'Former les élus locaux et le personnel communal à la gestion budgétaire et comptable des CTD.',
                'body' => "## Objectif\n\nFormer les **élus locaux** et le **personnel communal** à la gestion budgétaire et comptable des **Collectivités Territoriales Décentralisées (CTD)**. La formation met un accent sur la mobilisation et l'utilisation optimale des ressources transférées.\n\n## Public cible\n\n- Maires et conseillers municipaux\n- Secrétaires généraux des communes\n- Receveurs municipaux\n- Cadres du MINDDEVEL et du FEICOM\n\n## Compétences acquises\n\n- Élaboration et exécution du budget communal\n- Mobilisation des ressources locales (impôts, taxes, transferts)\n- Comptabilité publique locale\n- Reporting financier aux conseils municipaux\n\n{$tariffs}",
            ],
            'mobilisation-financements-exterieurs' => [
                'excerpt' => 'Opportunités de financement extérieur, exigences des bailleurs internationaux, modalités de gestion.',
                'body' => "## Objectif\n\nConçue avec l'appui de l'**Institut des Finances du Maroc**, cette formation éclaire les acteurs publics sur les opportunités de **financement extérieur**, les exigences des bailleurs internationaux, et les modalités de gestion efficace de ces fonds.\n\n## Public cible\n\n- Cadres des cellules de coopération internationale\n- Chefs de projets financés par bailleurs\n- Responsables de la dette publique\n- Négociateurs de conventions de financement\n\n## Compétences acquises\n\n- Cartographie des bailleurs (Banque mondiale, BAD, AFD, FMI, UE)\n- Préparation de dossiers de financement éligibles\n- Suivi des décaissements et reporting bailleur\n- Gestion fiduciaire et passation des marchés sur fonds extérieurs\n\n{$tariffs}",
            ],
            'marches-publics' => [
                'excerpt' => 'Procédures de passation, fiscalité des marchés, exécution financière, responsabilités contractuelles.',
                'body' => "## Objectif\n\nCette formation aide à s'approprier les exigences du **nouveau code des marchés publics**. Elle couvre les procédures de passation, la fiscalité des marchés, l'exécution financière, et les responsabilités contractuelles, tout en luttant contre les mauvaises pratiques.\n\n## Public cible\n\n- Cadres de l'ARMP\n- Membres des commissions de passation des marchés\n- Acheteurs publics\n- Auditeurs des marchés publics\n\n## Compétences acquises\n\n- Maîtrise du Code des marchés publics camerounais\n- Application des procédures de passation (AOO, AOR, gré à gré, MAPA)\n- Gestion fiscale des marchés\n- Détection et prévention des risques de fraude\n\n{$tariffs}",
            ],
            'chaine-ppbs' => [
                'excerpt' => 'Acteurs de la chaîne planification-programmation-budgétisation et suivi-évaluation : rôles et outils.',
                'body' => "## Objectif\n\nElle permet aux acteurs de la chaîne **PPBS** (Planification-Programmation-Budgétisation et Suivi-Évaluation) de mieux comprendre leur rôle et d'acquérir les outils nécessaires à l'élaboration des documents de planification et budgétisation. Elle favorise également une meilleure articulation entre les **stratégies nationales** et **sectorielles**.\n\n## Public cible\n\n- Responsables de la planification dans les ministères\n- Cadres du MINEPAT\n- Coordonnateurs de programmes sectoriels\n- Évaluateurs de politiques publiques\n\n## Compétences acquises\n\n- Articulation Stratégie nationale → Stratégies sectorielles → Programmes\n- Élaboration de documents de programmation pluriannuelle\n- Suivi-évaluation par indicateurs (logique GAR)\n- Reddition de comptes et reporting de performance\n\n{$tariffs}",
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function certificationsPage(): array
    {
        return [
            'slug' => 'formations/certifications',
            'parent_slug' => 'formations',
            'order' => 40,
            'is_in_menu' => true,
            'menu_label' => 'Certifications',
            'title' => 'Certifications internationales',
            'excerpt' => 'Certifications professionnelles en partenariat avec FMI, Expertise France, Institut des Finances du Maroc.',
            'body' => "## Certifications proposées\n\n- **CPAFP** — Certification Professionnelle d'Audit des Finances Publiques (4 semaines + examen)\n- **CMPC** — Certification en Marchés Publics du Cameroun (3 semaines + examen)\n- **CGTL** — Certification en Gestion Territoriale Locale (2 semaines + projet)\n\nLes examens se tiennent **deux fois par an** (juin et décembre).\n\n## Reconnaissance\n\nLes certifications sont reconnues par le **MINFI** et l'**ARMP**. Elles donnent droit à des bonifications dans la grille indiciaire des fonctionnaires de catégorie A au Cameroun.\n\n## Partenaires internationaux\n\n- **Fonds Monétaire International (FMI)** — modules sur la gestion de la dette\n- **Expertise France** — coopération technique\n- **Institut des Finances du Maroc** — gestion des financements extérieurs\n\n## Inscription\n\nContact : [usi@pssfp.org](mailto:usi@pssfp.org)\n\nVoir aussi [conditions générales d'admission](/formations/admission).",
        ];
    }

    /** @return array<string, mixed> */
    private function seminairesPage(): array
    {
        return [
            'slug' => 'formations/seminaires',
            'parent_slug' => 'formations',
            'order' => 45,
            'is_in_menu' => true,
            'menu_label' => 'Séminaires',
            'title' => 'Séminaires et voyages d\'étude',
            'excerpt' => 'Voyages d\'étude au Maroc et en France intégrés au cursus pédagogique du Master.',
            'body' => "Le PSSFP organise chaque année des **voyages d'étude** intégrés au cursus pédagogique du Master Professionnel.\n\n## Voyage d'étude au Maroc\n\nEn partenariat avec l'**Institut des Finances Basil Fuleihan** (Rabat), les étudiants découvrent :\n\n- L'organisation du système financier marocain\n- Les pratiques de gestion budgétaire au Maghreb\n- Les retours d'expérience sur la décentralisation\n- Des visites institutionnelles (Ministère des Finances, Cour des Comptes, ANCFCC)\n\n**Durée** : 1 semaine (généralement en M2).\n\n## Voyage d'étude en France\n\nEn partenariat avec **Expertise France** et l'**ENA** (École Nationale d'Administration), les étudiants accèdent à :\n\n- Les pratiques de gestion budgétaire à la française (LOLF, programmes)\n- L'audit et le contrôle des finances publiques (Cour des Comptes française)\n- Les enjeux européens des finances publiques (UE, BCE)\n- Des visites institutionnelles (Bercy, Sénat, Cour des Comptes)\n\n**Durée** : 10 jours (généralement en M2, fin de cursus).\n\n## Séminaires thématiques\n\nDes séminaires courts (1 à 3 jours) sont organisés ponctuellement avec des experts internationaux invités. Exemples récents :\n\n- Séminaire FMI sur la soutenabilité budgétaire (2025)\n- Séminaire OCDE sur les politiques fiscales (2024)\n\n## Coût\n\nLes voyages d'étude sont **inclus dans les frais de scolarité du Master** (1 185 000 FCFA / an).\n\nLes séminaires thématiques sont gratuits pour les étudiants en cours de cursus.",
        ];
    }

    /** @return array<string, mixed> */
    private function admissionPage(): array
    {
        return [
            'slug' => 'formations/admission',
            'parent_slug' => 'formations',
            'order' => 50,
            'is_in_menu' => false,
            'menu_label' => null,
            'title' => 'Admission au PSSFP',
            'excerpt' => 'Conditions d\'admission, calendrier P14 (2026), dossier et frais — cf. communiqué officiel d\'appel à candidature.',
            'body' => "## Conditions d'éligibilité\n\n- **Diplôme universitaire de niveau BAC+3** avec une formation de base en économie, gestion, droit ou discipline assimilée\n- **Justifier d'une expérience d'au moins cinq (5) ans** dans un service ou une structure ayant un lien direct avec les Finances Publiques\n- La validation des acquis de l'expérience (VAE) peut prévaloir selon la cohérence entre la formation et le projet professionnel du candidat\n\n## Calendrier — campagne P14 (2026)\n\n- **Ouverture des candidatures** : 27 juillet 2026\n- **Clôture** : 18 septembre 2026 à 15h30\n- **Résultats** : publiés par communiqué conjoint du Ministre des Finances et du Recteur de l'Université de Yaoundé II-Soa\n\n## Deux modalités de formation\n\n- **Présentiel** — Campus de Messa, Yaoundé : 25 places par filière\n- **Formation à distance** : 10 places par filière\n\nLa sélection se fait sur étude du dossier et entretien devant un jury pour le présentiel, sur étude du dossier uniquement pour la formation à distance.\n\n## Dossier de candidature\n\nLe dossier se constitue en ligne sur [apply.pssfp.org](https://apply.pssfp.org) : profil, photo d'identité (obligatoire) et paiement des frais en agence CREMINCAM.\n\nLes pièces justificatives (diplôme ou attestation de réussite, acte de naissance, relevés de notes, CV, lettre de motivation, attestation de présence au poste ou autorisation de l'employeur le cas échéant) peuvent être ajoutées en ligne **ou** apportées directement au bureau de la scolarité du PSSFP (Yaoundé-Messa, porte 231).\n\n## Frais\n\n- **Frais de candidature** : 50 000 FCFA non-remboursables, payables en agence CREMINCAM\n- **Frais de scolarité** : 1 185 000 FCFA / an (Master 1) + 1 135 000 FCFA / an (Master 2)\n\nCf. [Frais de scolarité](/formations/frais-de-scolarite).",
        ];
    }

    /** @return array<string, mixed> */
    private function fraisPage(): array
    {
        return [
            'slug' => 'formations/frais-de-scolarite',
            'parent_slug' => 'formations',
            'order' => 60,
            'is_in_menu' => false,
            'menu_label' => null,
            'title' => 'Frais de scolarité',
            'excerpt' => 'Frais détaillés par cursus et modalités de paiement (CREMINCAM).',
            'body' => "## Master Professionnel\n\n| Frais | Montant |\n|---|---|\n| **Frais de candidature** | 50 000 FCFA (non-remboursables) |\n| **Master 1 (M1)** | 1 185 000 FCFA / an |\n| **Master 2 (M2)** | 1 135 000 FCFA / an |\n| **Mémoire et soutenance** | 100 000 FCFA (au M2) |\n| **Total cycle complet** | **2 320 000 FCFA + 150 000 FCFA frais annexes** |\n\n## Formation continue\n\n| Public | Tarif |\n|---|---|\n| **Administrations** (session < 15 personnes) | 4 995 000 FCFA |\n| **Individus** | 500 000 FCFA / personne |\n| **Étudiants du PSSFP** | 250 000 FCFA / personne |\n\n**Durée** : 3 à 5 jours selon le module.\n\n## Certifications\n\n- CPAFP : 250 000 FCFA (formation + examen)\n- CMPC : 200 000 FCFA\n- CGTL : 180 000 FCFA\n\n## Modalités de paiement\n\nLes frais de candidature et de scolarité sont payables en agence **CREMINCAM** (références bancaires fournies au candidat). Le récépissé bancaire est à présenter à l'**UAAF** pour validation.\n\nÉchelonnement possible des frais de scolarité **en 3 tranches** sur l'année académique, sur demande motivée.",
        ];
    }
}
