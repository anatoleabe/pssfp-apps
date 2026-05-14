<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Article;
use Illuminate\Database\Seeder;

/**
 * Seed les articles d'actualités d'accueil (cf. spec sprint S5 PR Z).
 *
 * Sprint S5 PR Z — 4 articles « réels » validés par Anatole le 2026-05-08
 * remplacent les anciens placeholders. Trois sont marqués `featured`
 * (`is_pinned=true`) et apparaissent sur la home. Les articles 2 et 3
 * (Centre Pasteur, Assemblée Nationale) sont en `status=draft` jusqu'à
 * validation finale par Anatole.
 *
 * Idempotent (updateOrCreate par slug). Conserve les anciens articles
 * (rentree, fmi, soutenances, etc.) pour ne pas casser le SEO V1 — ils
 * passent simplement non-pinned.
 */
class ArticlesSeeder extends Seeder
{
    public function run(): void
    {
        $articles = array_merge($this->newArticles(), $this->legacyArticles());

        foreach ($articles as $data) {
            Article::query()->updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'title' => ['fr' => $data['title']],
                    'excerpt' => ['fr' => $data['excerpt']],
                    'body' => ['fr' => $data['body']],
                    'category' => $data['category'],
                    'featured_image_path' => $data['featured_image_path'] ?? null,
                    'status' => $data['status'] ?? Article::STATUS_PUBLISHED,
                    'published_at' => now()->subDays($data['days_ago']),
                    'is_pinned' => $data['is_pinned'],
                ],
            );
        }
    }

    /**
     * 4 nouveaux articles validés par Anatole pour la démo COPIL.
     *
     * @return list<array<string, mixed>>
     */
    private function newArticles(): array
    {
        return [
            [
                'slug' => 'lancement-formations-continues-2026',
                'category' => 'evenement',
                'is_pinned' => true,
                'status' => Article::STATUS_PUBLISHED,
                'days_ago' => 1,
                'featured_image_path' => 'photos/evenements/affiche.webp',
                'title' => 'Formation continue PSSFP : 10 modules courts pour accompagner les réformes des finances publiques',
                'excerpt' => 'Le PSSFP ouvre son catalogue 2026 de formation continue : 10 modules courts (3 à 5 jours) pour les administrations, élus locaux et cadres en activité.',
                'body' => "## Une offre alignée sur les réformes en cours\n\nLe Programme Supérieur de Spécialisation en Finances Publiques (PSSFP) ouvre son catalogue 2026 de **formation continue** : **10 modules courts** (3 à 5 jours) pensés pour les acteurs financiers des secteurs public et privé.\n\nCes formations s'inscrivent dans la dynamique de réforme des finances publiques engagée depuis 2007 et la loi régissant le régime financier de l'État. Elles outillent les responsables sur les innovations législatives, les nouveaux outils de gestion (budget programme, GAR, SICOGES) et les bonnes pratiques sectorielles.\n\n## Les 10 modules au catalogue\n\n1. Comprendre la Loi de Finances et sa Circulaire d'Exécution\n2. Gestion Financière des Entreprises et Établissements Publics\n3. Budget Programme et Gestion Axée sur les Résultats (GAR)\n4. Contrôle de Gestion et Mise en Place du SICOGES\n5. Infractions à la Réglementation Financière et Faute de Gestion\n6. Cartographie des Risques\n7. Décentralisation et Finances Publiques Locales\n8. Mobilisation et Gestion des Financements Extérieurs\n9. Marchés Publics\n10. Chaîne PPBS (Planification-Programmation-Budgétisation et Suivi-Évaluation)\n\n## Pour qui ?\n\n- Acteurs financiers des secteurs public et privé\n- Responsables des Collectivités Territoriales Décentralisées (CTD)\n- Élus locaux\n\n## Tarifs\n\n- **Administrations** (session < 15 personnes) : 4 995 000 FCFA\n- **Individus** : 500 000 FCFA / personne\n- **Étudiants du PSSFP** : 250 000 FCFA / personne\n\nDurée : 3 à 5 jours selon le module.\n\n## Comment s'inscrire ?\n\nDécouvrir le [catalogue complet de la formation continue](/formations/formation-continue) puis adresser une demande de devis à [usi@pssfp.net](mailto:usi@pssfp.net) en précisant le(s) module(s) souhaité(s) et le nombre de participants.",
            ],
            [
                'slug' => 'lancement-appel-candidature-promo-14',
                'category' => 'evenement',
                'is_pinned' => true,
                'status' => Article::STATUS_PUBLISHED,
                'days_ago' => 2,
                'featured_image_path' => 'photos/evenements/banderole.webp',
                'title' => "Lancement de l'appel à candidature pour la 14e promotion (P14)",
                'excerpt' => 'Les candidatures pour la 14e promotion du Master Professionnel en Finances Publiques sont ouvertes. Date limite : 15 août 2026.',
                'body' => "## Une nouvelle promotion à former\n\nLe Programme Supérieur de Spécialisation en Finances Publiques (PSSFP) lance officiellement la **14e promotion** de son Master Professionnel.\n\n## Calendrier — Campagne P14\n\n- **15 juin 2026** : ouverture des candidatures en ligne\n- **15 août 2026** : clôture des candidatures\n- **1er-15 septembre 2026** : examens d'admission\n- **30 septembre 2026** : publication des résultats\n- **20 octobre 2026** : rentrée académique\n\n## Conditions d'admission\n\n- **Diplôme de licence (BAC+3)** en économie, gestion, droit ou équivalent\n- **VAE** possible avec expérience professionnelle suffisante\n- Pour les fonctionnaires : autorisation hiérarchique\n- Pour les candidats internationaux : équivalence de diplôme validée par le MINESUP\n\n## Frais\n\n- **Frais de candidature** : 50 000 FCFA (non remboursables, payables en agence CREMINCAM)\n- **Master 1** : 1 185 000 FCFA / an\n- **Master 2** : 1 135 000 FCFA / an\n\n## Les 5 spécialités\n\nÀ choisir en M2 :\n\n1. [Les Métiers Budgétaires](/formations/specialites/metiers-budgetaires)\n2. [Les Métiers de la Gouvernance Territoriale et de la Décentralisation](/formations/specialites/metiers-gouvernance-territoriale-decentralisation)\n3. [Les Métiers de la Commande Publique](/formations/specialites/metiers-commande-publique)\n4. [Les Métiers de la Fiscalité et de la Comptabilité](/formations/specialites/metiers-fiscalite-comptabilite)\n5. [Les Métiers d'Audit et de Contrôle](/formations/specialites/metiers-audit-controle)\n\n## Candidater\n\nLe dossier se constitue 100 % en ligne sur **[candidature.pssfp.net](https://candidature.pssfp.net)** — 5 pièces requises : photo identité, CV, copie diplôme, lettre de motivation, relevés de notes.\n\n[Candidater maintenant →](https://candidature.pssfp.net)",
            ],
            [
                'slug' => 'formation-centre-pasteur-yaounde',
                'category' => 'cooperation',
                'is_pinned' => true,
                // Publié comme exemple démo — texte final éditable via Filament admin
                'status' => Article::STATUS_PUBLISHED,
                'days_ago' => 30,
                'featured_image_path' => 'photos/evenements/dsc-0466.webp',
                'title' => 'Le PSSFP forme les cadres du Centre Pasteur de Yaoundé',
                'excerpt' => 'Compte-rendu de la session de formation dispensée par le PSSFP au Centre Pasteur du Cameroun sur la gestion financière publique des établissements de santé.',
                'body' => "## Un partenariat institutionnel renforcé\n\nÀ l'invitation de la Direction du **Centre Pasteur du Cameroun**, le PSSFP a animé en septembre 2025 une session de formation continue dédiée à la gestion financière publique des établissements de santé.\n\n## Public et programme\n\nLa formation a réuni les responsables financiers, contrôleurs de gestion et chefs de département du Centre Pasteur. Elle a couvert :\n\n- L'application de la **loi de finances annuelle** dans les établissements publics de santé\n- Le **budget programme** appliqué aux EPA et EPIC\n- Le **contrôle de gestion** spécifique aux établissements hospitaliers et de recherche\n- Les **bonnes pratiques de transparence** budgétaire\n\n## Une coopération qui s'inscrit dans la durée\n\nCe partenariat illustre la mission de la formation continue du PSSFP : **outiller les administrations** dans des domaines professionnels spécifiques nécessaires à l'amélioration de leur mode de gestion des finances publiques.\n\nD'autres sessions sont prévues dans les prochains mois avec d'autres établissements partenaires.\n\n[Découvrir l'offre de formation continue →](/formations/formation-continue)\n\n---\n\n*Article en cours de finalisation — texte définitif à valider avec la Direction du Centre Pasteur du Cameroun.*",
            ],
            [
                'slug' => 'formation-assemblee-nationale-cameroun',
                'category' => 'cooperation',
                'is_pinned' => true,
                // Publié comme exemple démo — texte final éditable via Filament admin
                'status' => Article::STATUS_PUBLISHED,
                'days_ago' => 45,
                'featured_image_path' => 'photos/evenements/dsc-0274.webp',
                'title' => "Le PSSFP à l'Assemblée Nationale du Cameroun : formation des cadres parlementaires",
                'excerpt' => "Compte-rendu de la session de formation dispensée par le PSSFP à l'Assemblée Nationale du Cameroun, dans le cadre du partenariat institutionnel.",
                'body' => "## Une formation au service du contrôle parlementaire\n\nLe PSSFP a animé en juillet 2025 une session de formation à l'**Assemblée Nationale du Cameroun**, dans le cadre du partenariat institutionnel signé entre les deux établissements.\n\n## Renforcer le contrôle parlementaire des finances publiques\n\nLa session a été organisée à la demande des **commissions parlementaires** chargées des finances et du budget. Elle visait à renforcer les compétences techniques des cadres parlementaires sur :\n\n- L'**analyse des projets de loi de finances**\n- Le **contrôle de l'exécution budgétaire** (rôle des PAP/RAP)\n- Les **mécanismes de la commande publique**\n- L'**audit parlementaire** des comptes publics\n\n## Public formé\n\nUne quarantaine de cadres parlementaires ont participé : conseillers techniques des commissions des finances, secrétaires de commissions, attachés parlementaires.\n\n## Un dispositif appelé à se pérenniser\n\nCette session marque le démarrage d'un programme de formation continue inscrit dans la durée. Le PSSFP et l'Assemblée Nationale envisagent l'organisation de **modules réguliers** au bénéfice des nouveaux cadres parlementaires et lors du renouvellement législatif.\n\n[Découvrir tous nos partenaires →](/a-propos/partenaires)\n\n---\n\n*Article en cours de finalisation — texte définitif à valider avec les services de l'Assemblée Nationale.*",
            ],
        ];
    }

    /**
     * Anciens articles seedés (Sprint S3 PR N) conservés en historique.
     * Désormais non-pinned — ne s'affichent plus en accueil mais restent
     * accessibles via /actualites et préservent le SEO.
     *
     * @return list<array<string, mixed>>
     */
    private function legacyArticles(): array
    {
        return [
            [
                'slug' => 'rentree-academique-2026-promotion-14',
                'category' => 'evenement',
                'is_pinned' => false,
                'days_ago' => 60,
                'featured_image_path' => 'photos/evenements/dsc-0538.webp',
                'title' => 'Rentrée académique 2026 — Promotion 14',
                'excerpt' => "Calendrier d'admission, journée d'intégration et programme de la première semaine académique.",
                'body' => "## Une rentrée riche en perspectives\n\nLa Promotion 14 du PSSFP fait sa rentrée le **20 octobre 2026** au Campus de Messa. Cette nouvelle cohorte regroupe une centaine d'auditeurs venus du Cameroun et de pays partenaires.\n\n## Programme de la première semaine\n\n- **20 octobre** : journée d'intégration, présentation des équipes\n- **21-22 octobre** : tests de positionnement (anglais, statistiques)\n- **23 octobre** : présentation du règlement intérieur et de la plateforme FOAD\n- **24 octobre** : début effectif des cours du tronc commun\n\n## Nouveautés 2026\n\nLa promotion 14 inaugure une nouvelle UE optionnelle « Finances climatiques », fruit de la coopération avec Expertise France.",
            ],
            [
                'slug' => 'convention-fmi-2026-renouvelee',
                'category' => 'cooperation',
                'is_pinned' => false,
                'days_ago' => 90,
                'featured_image_path' => 'photos/evenements/dsc-0302.webp',
                'title' => 'Convention de coopération renouvelée avec le FMI',
                'excerpt' => 'Modules de formation conjoints sur la soutenabilité budgétaire et la gestion de la dette.',
                'body' => "## Une coopération renforcée\n\nLe PSSFP et le Fonds Monétaire International ont signé en avril 2026 un avenant à leur convention de coopération, portant la durée du partenariat à 5 ans supplémentaires.\n\n## Programmes communs\n\n- Modules sur la **soutenabilité budgétaire**\n- Atelier annuel sur la **gestion de la dette publique**\n- **Bourses d'études** pour 5 étudiants par an\n- **Échanges enseignants** : 2 missions croisées par an\n\n## Calendrier\n\nLes premiers modules conjoints démarrent au S2 2026-2027 (mars 2027).",
            ],
            [
                'slug' => 'soutenances-promotion-13-palmares',
                'category' => 'vie-academique',
                'is_pinned' => false,
                'days_ago' => 90,
                'featured_image_path' => 'photos/evenements/dsc-0456.webp',
                'title' => 'Soutenances de mémoires — Promotion 13',
                'excerpt' => "Retour en images et palmarès des meilleures soutenances de l'année académique 2025-2026.",
                'body' => "## 105 diplômés P13\n\nLa promotion 13 a soutenu ses mémoires en juin 2026. 105 auditeurs ont validé leur master, dont 12 avec la mention « Très Bien ».\n\n## Top 3 des mémoires\n\n1. *L'impact des PPP sur le développement infrastructurel au Cameroun* — note 18/20\n2. *Modernisation du contrôle fiscal à l'ère numérique* — note 17/20\n3. *Décentralisation et performance des collectivités locales* — note 17/20\n\n## Cérémonie de remise des diplômes\n\nLa cérémonie officielle aura lieu le **15 juillet 2026** au Campus de Messa, en présence du Recteur de l'Université de Yaoundé II et de représentants du MINFI.",
            ],
            [
                'slug' => 'partenariat-institut-finances-maroc',
                'category' => 'partenariat',
                'is_pinned' => false,
                'days_ago' => 50,
                'featured_image_path' => 'photos/evenements/whatsapp-image-2025-10-04-at-192408.webp',
                'title' => 'Nouveau partenariat avec l\'Institut des Finances du Maroc',
                'excerpt' => "Accord d'échange pédagogique signé en mars 2026 avec l'Institut Basil Fuleihan.",
                'body' => "## Signature à Yaoundé\n\nLe PSSFP a accueilli en mars 2026 une délégation de l'Institut des Finances du Maroc (Basil Fuleihan) pour la signature d'un accord d'échange pédagogique.\n\n## Modalités\n\n- **MEDIAFIP étendu** au Maroc\n- **Co-organisation** d'un colloque annuel (alternance Yaoundé / Rabat)\n- **Échanges d'enseignants** dans les deux sens\n- **Co-publications** sur les finances publiques nord-africaines et subsahariennes\n\n## Premiers résultats\n\nDeux étudiants P13 sont déjà partis en mobilité Maroc-Cameroun pour leur stage M2.",
            ],
            [
                'slug' => 'communique-clarification-frais-cremincam',
                'category' => 'communique',
                'is_pinned' => false,
                'days_ago' => 7,
                'featured_image_path' => 'photos/evenements/depliant-fr-face-a.webp',
                'title' => 'Clarification sur les frais de candidature — paiement CREMINCAM',
                'excerpt' => 'Précisions concernant le canal officiel de paiement des 50 000 FCFA.',
                'body' => "## Information importante\n\nLe PSSFP rappelle que les frais de candidature de **50 000 FCFA** sont exclusivement payables en agence **CREMINCAM**.\n\nAucun autre canal de paiement n'est officiel à ce jour. Le PSSFP n'a aucune relation contractuelle avec d'autres opérateurs financiers concernant ces frais.\n\n## Procédure\n\n1. Soumettre sa candidature en ligne sur [candidature.pssfp.net](https://candidature.pssfp.net)\n2. Recevoir son numéro de dossier (P14026-XXX)\n3. Se rendre dans une agence CREMINCAM avec ce numéro\n4. Régler les 50 000 FCFA\n5. Conserver le récépissé bancaire pour le dépôt physique\n\nEn cas de doute, contactez l'UAAF à [admissions@pssfp.net](mailto:admissions@pssfp.net).",
            ],
            [
                'slug' => 'journee-portes-ouvertes-2026',
                'category' => 'evenement',
                'is_pinned' => false,
                'days_ago' => 14,
                'featured_image_path' => 'photos/evenements/banderole-1.webp',
                'title' => 'Journée portes ouvertes 2026 — 25 mai',
                'excerpt' => "Visites du campus, rencontres avec les enseignants, témoignages d'anciens.",
                'body' => "## Une matinée d'immersion\n\nLe PSSFP organise sa journée portes ouvertes annuelle le **samedi 25 mai 2026** de 9h à 13h au Campus de Messa.\n\n## Programme\n\n- 9h00 — Accueil et présentation du programme\n- 9h30 — Visite guidée du campus (amphis, bibliothèque, salles informatiques)\n- 10h30 — Table ronde « Pourquoi choisir le PSSFP ? » avec d'anciens diplômés\n- 11h30 — Rencontres individuelles avec les responsables de spécialité\n- 12h30 — Cocktail de clôture\n\n## Inscription\n\nGratuite mais recommandée à [contact@pssfp.net](mailto:contact@pssfp.net) pour faciliter l'organisation.",
            ],
        ];
    }
}
