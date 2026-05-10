<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Page;
use Illuminate\Database\Seeder;

/**
 * Seed les pages de la rubrique « À propos de nous » (cf. spec sprint S5 PR W).
 *
 * Remplace l'ancien `PssfpPagesSeeder` :
 *   - slugs `pssfp/...` → `a-propos/...` (avec redirect 301 côté frontend),
 *   - menu label « Le PSSFP » → « À propos de nous »,
 *   - contenu seedé depuis les vrais documents extraits :
 *     * docs/sources/content/mot-president.md
 *     * docs/sources/content/presentation-pssfp.md
 *     * docs/sources/content/organigramme.md
 *   - terminologie corrigée : Pr. BASAHAG = Président du Comité de
 *     Pilotage (PCP), PAS Directeur Général (il n'y a pas de DG au PSSFP).
 *
 * Idempotent : updateOrCreate par slug.
 */
class AProposPagesSeeder extends Seeder
{
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
     * @return list<array{slug: string, parent_slug: string, order: int, is_in_menu: bool, menu_label: string, title: string, excerpt: string, body: string}>
     */
    private function pages(): array
    {
        return [
            [
                'slug' => 'a-propos/mot-president',
                'parent_slug' => 'a-propos',
                'order' => 10,
                'is_in_menu' => true,
                'menu_label' => 'Mot du Président',
                'title' => 'Mot du Président',
                'excerpt' => 'Pr. BASAHAG Achile Nestor, Président du Comité de Pilotage du PSSFP, vous accueille sur le site officiel.',
                'body' => $this->motPresidentBody(),
            ],
            [
                'slug' => 'a-propos/presentation',
                'parent_slug' => 'a-propos',
                'order' => 20,
                'is_in_menu' => true,
                'menu_label' => 'Présentation du PSSFP',
                'title' => 'Présentation du PSSFP',
                'excerpt' => "Programme Supérieur de Spécialisation en Finances Publiques — institut du Ministère des Finances créé le 9 octobre 2013.",
                'body' => $this->presentationBody(),
            ],
            [
                'slug' => 'a-propos/comite-pilotage',
                'parent_slug' => 'a-propos',
                'order' => 30,
                'is_in_menu' => true,
                'menu_label' => 'Comité de Pilotage',
                'title' => 'Comité de Pilotage',
                'excerpt' => "Le PSSFP est dirigé par un Comité de Pilotage présidé par le Pr. BASAHAG Achile Nestor — pas par un Directeur Général.",
                'body' => $this->comitePilotageBody(),
            ],
            [
                'slug' => 'a-propos/organigramme',
                'parent_slug' => 'a-propos',
                'order' => 40,
                'is_in_menu' => true,
                'menu_label' => 'Organigramme',
                'title' => 'Organigramme du PSSFP',
                'excerpt' => 'Comité de Pilotage, Comité Scientifique, départements de formation, unités fonctionnelles et centre de documentation.',
                'body' => $this->organigrammeBody(),
            ],
            [
                'slug' => 'a-propos/convention-tripartite',
                'parent_slug' => 'a-propos',
                'order' => 50,
                'is_in_menu' => true,
                'menu_label' => 'Convention tripartite',
                'title' => 'Convention tripartite (MINFI · MINESUP · UY2)',
                'excerpt' => "Convention signée le 9 octobre 2013 entre le Ministère des Finances, le Ministère de l'Enseignement Supérieur et l'Université de Yaoundé II-Soa.",
                'body' => $this->conventionTripartiteBody(),
            ],
            [
                'slug' => 'a-propos/histoire',
                'parent_slug' => 'a-propos',
                'order' => 60,
                'is_in_menu' => true,
                'menu_label' => 'Histoire',
                'title' => 'Histoire du PSSFP depuis 2013',
                'excerpt' => 'De la convention tripartite du 9 octobre 2013 aux 13 promotions diplômées.',
                'body' => $this->histoireBody(),
            ],
            [
                'slug' => 'a-propos/infrastructure',
                'parent_slug' => 'a-propos',
                'order' => 70,
                'is_in_menu' => true,
                'menu_label' => 'Infrastructure',
                'title' => 'Infrastructure et campus',
                'excerpt' => 'Campus de Messa à Yaoundé : 6 amphithéâtres, 15 bureaux modernes, bibliothèque hybride.',
                'body' => $this->infrastructureBody(),
            ],
            [
                'slug' => 'a-propos/partenaires',
                'parent_slug' => 'a-propos',
                'order' => 80,
                'is_in_menu' => true,
                'menu_label' => 'Partenaires',
                'title' => 'Nos partenaires institutionnels',
                'excerpt' => 'MINFI, MINESUP, UY2, Expertise France, FMI, Institut des Finances du Maroc, Assemblée Nationale du Cameroun.',
                'body' => $this->partenairesBody(),
            ],
            [
                'slug' => 'a-propos/conformite-cames',
                'parent_slug' => 'a-propos',
                'order' => 90,
                'is_in_menu' => true,
                'menu_label' => 'Conformité CAMES',
                'title' => 'Conformité aux exigences CAMES',
                'excerpt' => "Tableau récapitulatif des 12 exigences du Conseil Africain et Malgache pour l'Enseignement Supérieur.",
                'body' => $this->conformiteCamesBody(),
            ],
        ];
    }

    private function motPresidentBody(): string
    {
        return <<<'MD'
> *Bienvenue sur le site officiel du Programme Supérieur de Spécialisation en Finances Publiques (PSSFP).*

Créé sous l'égide du Ministère des Finances du Cameroun, le PSSFP s'est imposé comme un cadre d'excellence dédié à la formation, au renforcement des capacités et à la professionnalisation des acteurs des finances publiques. Notre ambition est de contribuer activement à la modernisation de l'action publique à travers une offre de formation spécialisée, adaptée aux enjeux contemporains de gouvernance, de performance et de développement.

Dans un environnement marqué par l'évolution rapide des politiques publiques, la transformation numérique et les exigences accrues en matière de transparence et d'efficacité, le PSSFP entend former une nouvelle génération de cadres capables d'accompagner durablement les réformes de l'État et des collectivités territoriales. Nos formations couvrent des domaines stratégiques tels que l'audit et le contrôle des finances publiques, la fiscalité, la gouvernance territoriale, les marchés publics ainsi que l'économie et la gestion publiques.

Le PSSFP place également l'innovation pédagogique au cœur de son action. À travers le développement de son dispositif de Formation Ouverte et À Distance (FOAD), nous avons engagé une dynamique de digitalisation visant à améliorer l'accessibilité, la flexibilité et la qualité des enseignements. Cette orientation traduit notre volonté de bâtir un environnement académique moderne, inclusif et résolument tourné vers l'avenir.

Au-delà des enseignements, le PSSFP constitue un espace d'échanges, de collaboration et de production intellectuelle au service de la performance publique. Nous croyons fermement que le renforcement des compétences, l'intégrité professionnelle et la culture de résultats représentent des leviers essentiels pour une gestion saine et efficace des ressources publiques.

Je tiens à saluer l'engagement des équipes pédagogiques, des partenaires institutionnels, des experts et des apprenants qui participent quotidiennement au rayonnement de notre programme. Grâce à leur implication, le PSSFP continue de consolider sa position comme référence académique et professionnelle en matière de finances publiques.

À toutes celles et ceux qui découvrent notre institution, je souhaite la bienvenue et vous invite à parcourir ce site afin de mieux connaître nos missions, nos formations et nos perspectives.

---

**Pr. BASAHAG Achile Nestor**
*Président du Comité de Pilotage du Programme Supérieur de Spécialisation en Finances Publiques (PSSFP)*
MD;
    }

    private function presentationBody(): string
    {
        return <<<'MD'
## Le Programme Supérieur de Spécialisation en Finances Publiques (PSSFP)

Le **PSSFP** a été créé à la suite d'une **convention tripartite** signée le **9 octobre 2013** entre :

- le Ministre des Finances, **Alamine Ousmane MEY**,
- le Ministre de l'Enseignement Supérieur, **Pr. Jacques FAME NDONGO**,
- et le Recteur de l'Université de Yaoundé II-Soa, **Pr. OUMAROU BOUBA**.

Ce programme constitue une réponse du gouvernement dans le cadre de la **réforme des finances publiques**, telle que consacrée par la **loi de 2007 portant régime financier de l'État**, qui exige de nouvelles compétences professionnelles pour sa mise en œuvre.

## Notre mission

Promouvoir à la fois la **formation initiale** (Master Professionnel en Finances Publiques) et la **formation continue** (10 modules courts) à destination des acteurs publics : fonctionnaires de l'État, cadres des collectivités territoriales, élus locaux, professionnels du secteur privé en lien avec la commande publique.

## Offre de formation

### Master Professionnel — 5 spécialités

- Les Métiers Budgétaires
- Les Métiers de la Gouvernance Territoriale et de la Décentralisation
- Les Métiers de la Commande Publique
- Les Métiers de la Fiscalité et de la Comptabilité
- Les Métiers d'Audit et de Contrôle

### Formation continue — 10 modules courts

Sessions de 3 à 5 jours pour les administrations, individus et étudiants du PSSFP. Cf. [Catalogue Formation continue](/formations/formation-continue).

## Quelques chiffres

- **13 promotions** diplômées depuis 2013
- **6 amphithéâtres** au campus de Messa
- **15 bureaux modernes** équipés
- **Bibliothèque hybride** physique + virtuelle
- Étudiants issus de **FEICOM, Assemblée Nationale, MINFI, MINDEF, MINEPAT** et autres structures publiques

Le programme doit son bon fonctionnement au **leadership du Président du Comité de Pilotage**, Pr. BASAHAG Achile Nestor, ainsi qu'à l'esprit d'équipe qui anime le personnel.
MD;
    }

    private function comitePilotageBody(): string
    {
        return <<<'MD'
Le PSSFP est dirigé par un **Comité de Pilotage (COPIL)** — il n'existe pas de poste de Directeur Général.

## Missions du Comité de Pilotage

- Veiller à la mise en œuvre et au suivi de la **Convention tripartite du 9 octobre 2013** ;
- Assurer la **gestion managériale globale** du PSSFP ;
- Examiner et adopter les **projets, rapports de performance, budget et différents comptes** du Programme.

## Composition

| Fonction | Personne |
|---|---|
| **Président du Comité de Pilotage (PCP)** | **Pr. BASAHAG Achile Nestor** |
| Conseiller juridique | Pr. ABANE ENGOLO Patrick |
| Conseiller technique | Dr. BANOCK BAMBOCK Eric Vincent |
| Contrôleur de Gestion | M. MOLLO Davy Claude Aubin |
| Contrôleur interne | M. MELO LASSA FUESSOH |

## Articulation avec les autres organes

- **Comité Scientifique** (présidé par le Pr. AVOM Désiré) — animation académique, collation des diplômes, certification.
- **Départements de Formation** — organisation pédagogique des 5 spécialités.
- **Unités fonctionnelles** (UPAAS, UAAF, USI, UDCFC, Centre de Documentation) — gestion opérationnelle quotidienne.

Cf. [Organigramme complet](/a-propos/organigramme).
MD;
    }

    private function organigrammeBody(): string
    {
        return <<<'MD'
## 1. Comité de Pilotage

- **Président** : Pr. BASAHAG Achile Nestor
- **Conseiller juridique** : Pr. ABANE ENGOLO Patrick
- **Conseiller technique** : Dr. BANOCK BAMBOCK Eric Vincent
- **Contrôleur de Gestion** : M. MOLLO Davy Claude Aubin
- **Contrôleur interne** : M. MELO LASSA FUESSOH

## 2. Comité Scientifique

- **Président** : Pr. AVOM Désiré
- **Vice-Présidente** : Mme NDENDE Caroline
- **Assistant de la vice-Présidente** : M. FOUDA EKOUDI François

## 3. Départements de Formation

- **Fiscalité, finance et comptabilité publique** : M. BILONG BI NGAWE Félix Ferry
- **Audit et contrôle** : M. NDJOM NACK Elie Désiré
- **Économie publique et gestion publique** : Pr. TAMBA Isaac
- **Gouvernance territoriale et finances publiques locales** : Hon. MBARGA ASSEMBE Luc Roger
- **Marchés publics et PPP** : M. TCHOFFO Jean

## 4. Unité de la Programmation des Activités Académiques et de la Scolarité (UPAAS)

- **Chef d'unité** : M. MBIANA Jean Paul
- M. NGUINI NYAMA Moïse Bertrand
- M. ZOFOA KUMBI Solomon
- M. BAVOUA Constant
- M. ASSOA NGAH Evrard

## 5. Unité des Affaires Administratives et Financières (UAAF)

- **Chef d'unité** : M. MBA Pierre
- Mme BAHANAG Mathilde Joséphine
- Mme ATSINA EBOGO Séraphine Madonne
- M. AYISSI AKONO Joël Arnaud Malachie
- Mme NGO KAMLA Marie Solange
- Mme BOT Falone Nadia
- M. MBARGA Joseph

## 6. Unité des Systèmes d'Information (USI)

- **Chef d'unité** : M. ABE ETOUMOU Anatole
- M. BELINGA Joseph Cédric
- M. MBIDA MBIDA Théodore Xavier
- M. BELINGA Joseph Giresse
- M. SIKE Manfred

## 7. Unité du Développement, de la Coopération et de la Formation Continue (UDCFC)

- **Chef d'unité** : Dr. MBALLA ZAMBO Edouard Georges
- Dr. MEMONO Jean Jacques Christian
- Dr. LEVODO NGAH Gervais Yannick
- Mme BONGO Daniela Anna

## 8. Centre de documentation

- **Chef de centre** : M. BENOH BENOH Pierre Tanguy
- Mme BEKONO MFOU'OU Marlène

## 9. Personnel en complément d'effectif

M. ONAMBELE EMBOLO · Mme NGO SAIDJOB Jacqueline · M. Julius SAKWE BONGSIRU · Mme NGO BOUMTJE Marie-Merveille · M. FOUDA OTTOU Aloïs Alain · M. ETOUNDI TSALA Merlin · M. EBOGO Jean Claude · M. ONDAFE MOLENG Cédric · Mme MBEGA BIGO Nella Juanita · M. AYOMBA Richard · M. BELLA ONGUENE Guy
MD;
    }

    private function conventionTripartiteBody(): string
    {
        return <<<'MD'
## Cadre juridique

Le PSSFP a été créé par la **convention tripartite** signée le **9 octobre 2013** dans le cadre de la **réforme des finances publiques**, consacrée par la **loi de 2007 portant régime financier de l'État**.

## Les trois signataires

| Institution | Signataire | Rôle |
|---|---|---|
| **Ministère des Finances (MINFI)** | Alamine Ousmane MEY | Tutelle technique — finances publiques |
| **Ministère de l'Enseignement Supérieur (MINESUP)** | Pr. Jacques FAME NDONGO | Tutelle académique — diplômes universitaires |
| **Université de Yaoundé II — Soa (UY2)** | Pr. OUMAROU BOUBA | Partenaire universitaire — collation des diplômes Master |

## Objet de la convention

- Créer un **pôle d'excellence** pour la formation des cadres de très haut niveau devant accompagner la réforme budgétaire et comptable engagée depuis 2013.
- Délivrer des **Masters Professionnels** en finances publiques reconnus dans le système universitaire camerounais.
- Mutualiser les ressources de l'État, du système universitaire et de l'administration des finances pour répondre aux besoins en compétences du secteur public.

## Documents officiels

Les conventions et leurs avenants sont consultables auprès de l'**UDCFC** (Unité du Développement, de la Coopération et de la Formation Continue).

- Convention tripartite originale du 9 octobre 2013 (PDF disponible)
- Convention PSSFP-MINFI-MINESUP en français (PDF disponible)

Une convention renouvelée a été signée en 2024 pour étendre le périmètre du programme.
MD;
    }

    private function histoireBody(): string
    {
        return <<<'MD'
## Origines : la réforme des finances publiques (2007)

La **loi du 26 décembre 2007 portant régime financier de l'État** transforme en profondeur la gestion publique camerounaise : passage du budget de moyens au **budget programme**, gestion axée sur les résultats, nouvelles responsabilités pour les ordonnateurs et comptables. Cette réforme exige des compétences nouvelles que les filières universitaires existantes ne pouvaient seules combler.

## Création (9 octobre 2013)

Pour répondre à ce besoin, le **MINFI**, le **MINESUP** et l'**Université de Yaoundé II-Soa** signent une **convention tripartite** créant le PSSFP. Le programme s'installe au **Campus de Messa** à Yaoundé.

## Premières promotions (2013-2018)

- 6 promotions diplômées entre 2013 et 2018, avec une moyenne de 80 à 100 diplômés par promotion.
- Étudiants issus du **FEICOM**, de l'**Assemblée Nationale**, du **MINFI**, du **MINDEF**, du **MINEPAT** et d'autres structures publiques.

## Consolidation (2019-2023)

- Mise en place du **dispositif FOAD** (Formation Ouverte et À Distance) pour étendre l'accessibilité.
- Développement de la **formation continue** (10 modules courts) à destination des administrations, élus locaux et entreprises publiques.
- Partenariats internationaux noués : **Institut des Finances du Maroc**, **Expertise France**, **FMI**.

## 2024 : nouvelle convention et 13e promotion

- **Renouvellement** de la convention tripartite en 2024.
- **13 promotions** diplômées au total — la promotion 12 finalise sa spécialisation, la 13 vient d'achever son cursus.

## 2026 : ouverture de la 14e promotion

- **Lancement officiel** de l'appel à candidature pour la promotion 14 (P14) en mai 2026.
- Mise en ligne du nouveau site institutionnel et de la plateforme de candidature en ligne.

## Direction historique

Le PSSFP a toujours été dirigé par un **Comité de Pilotage** présidé par un universitaire ou cadre de l'administration des finances. Depuis sa création, le programme est piloté collégialement par cette instance, sans poste de Directeur Général unique.

Le **Pr. BASAHAG Achile Nestor** assure aujourd'hui la présidence de ce Comité de Pilotage.
MD;
    }

    private function infrastructureBody(): string
    {
        return <<<'MD'
## Campus de Messa, Yaoundé

Le PSSFP est hébergé au **Campus de Messa**, en plein cœur de Yaoundé. Le site dispose d'équipements adaptés à la formation supérieure professionnelle.

## Espaces d'enseignement

- **6 amphithéâtres** modernes équipés
- **15 bureaux modernes** aux standards internationaux
- Salles de cours équipées de vidéoprojecteurs
- Espace informatique connecté

## Bibliothèque hybride

- **Bibliothèque physique** au campus de Messa : ouvrages spécialisés en finances publiques, droit budgétaire, fiscalité, audit, gouvernance territoriale.
- **Bibliothèque virtuelle** : accès distant à des milliers de ressources numériques (cours, manuels, articles, jurisprudence).

[Accéder à la bibliothèque virtuelle →](https://bibliotheque.pssfp.net)

## Plateforme FOAD

Les étudiants en distanciel accèdent aux cours via la plateforme Moodle [foad.pssfp.net](https://foad.pssfp.net), accessible 24h/24, 7j/7.

## Modalités d'enseignement

- **Présentiel** : Master Professionnel sur deux ans, formation continue de 3 à 5 jours selon les modules.
- **Distanciel** : FOAD pour les étudiants ne pouvant se rendre au campus.
- **Hybride** : combinaison possible selon les profils des apprenants.

## Adresse

**Campus de Messa**
Yaoundé — Cameroun
Tél. : (à compléter — UAAF)
Email : usi@pssfp.net
MD;
    }

    private function partenairesBody(): string
    {
        return <<<'MD'
## Partenaires nationaux

- **Ministère des Finances (MINFI)** — tutelle technique du programme.
- **Ministère de l'Enseignement Supérieur (MINESUP)** — tutelle académique.
- **Université de Yaoundé II — Soa** — partenaire universitaire principal, collation des diplômes Master.
- **Assemblée Nationale du Cameroun** — coopération sur la formation continue des cadres parlementaires.
- **Centre Pasteur du Cameroun** — formations spécifiques sur la gestion financière publique des établissements de santé.

## Partenaires internationaux

- **Fonds Monétaire International (FMI)** — modules de soutenabilité budgétaire et gestion de la dette.
- **Expertise France** — coopération technique financière et juridique.
- **Institut des Finances du Maroc (Basil Fuleihan)** — échanges pédagogiques, voyages d'étude.
- **AFD (Agence Française de Développement)** — appui ponctuel sur les financements extérieurs.

## Échanges et voyages d'étude

Des **voyages d'étude** au Maroc et en France sont organisés annuellement pour les étudiants du Master, intégrés au cursus pédagogique. Cf. [Séminaires et voyages d'étude](/formations/seminaires).

Une mission de coopération avec la **CEMAC** est en cours de structuration.
MD;
    }

    private function conformiteCamesBody(): string
    {
        return <<<'MD'
Le PSSFP s'inscrit dans le cadre de l'accréditation **CAMES** (Conseil Africain et Malgache pour l'Enseignement Supérieur), garante de la qualité académique au niveau régional.

Le tableau des **12 exigences CAMES** est consultable en bas de cette page. Pour chaque exigence, un lien vers la page institutionnelle du PSSFP qui en démontre la satisfaction.

*Cette page intègre un tableau spécial rendu côté frontend (cf. spec module 1 PR K).*
MD;
    }
}
