<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Article;
use Illuminate\Database\Seeder;

/**
 * Seed un échantillon d'articles d'actualités pour la démo COPIL et la
 * section accueil (cf. spec module 1 PR N).
 *
 * Idempotent (updateOrCreate par slug).
 */
class ArticlesSeeder extends Seeder
{
    public function run(): void
    {
        $articles = [
            [
                'slug' => 'rentree-academique-2026-promotion-14',
                'category' => 'evenement',
                'is_pinned' => true,
                'days_ago' => 3,
                'title' => 'Rentrée académique 2026 — Promotion 14',
                'excerpt' => "Calendrier d'admission, journée d'intégration et programme de la première semaine académique.",
                'body' => "## Une rentrée riche en perspectives\n\nLa Promotion 14 du PSSFP fait sa rentrée le **20 octobre 2026** au Campus de Messa. Cette nouvelle cohorte regroupe une centaine d'auditeurs venus du Cameroun et de pays partenaires.\n\n## Programme de la première semaine\n\n- **20 octobre** : journée d'intégration, présentation des équipes\n- **21-22 octobre** : tests de positionnement (anglais, statistiques)\n- **23 octobre** : présentation du règlement intérieur et de la plateforme FOAD\n- **24 octobre** : début effectif des cours du tronc commun\n\n## Nouveautés 2026\n\nLa promotion 14 inaugure une nouvelle UE optionnelle « Finances climatiques », fruit de la coopération avec Expertise France.",
            ],
            [
                'slug' => 'convention-fmi-2026-renouvelee',
                'category' => 'cooperation',
                'is_pinned' => true,
                'days_ago' => 25,
                'title' => 'Convention de coopération renouvelée avec le FMI',
                'excerpt' => 'Modules de formation conjoints sur la soutenabilité budgétaire et la gestion de la dette.',
                'body' => "## Une coopération renforcée\n\nLe PSSFP et le Fonds Monétaire International ont signé en avril 2026 un avenant à leur convention de coopération, portant la durée du partenariat à 5 ans supplémentaires.\n\n## Programmes communs\n\n- Modules sur la **soutenabilité budgétaire**\n- Atelier annuel sur la **gestion de la dette publique**\n- **Bourses d'études** pour 5 étudiants par an\n- **Échanges enseignants** : 2 missions croisées par an\n\n## Calendrier\n\nLes premiers modules conjoints démarrent au S2 2026-2027 (mars 2027).",
            ],
            [
                'slug' => 'soutenances-promotion-13-palmares',
                'category' => 'vie-academique',
                'is_pinned' => false,
                'days_ago' => 90,
                'title' => 'Soutenances de mémoires — Promotion 13',
                'excerpt' => "Retour en images et palmarès des meilleures soutenances de l'année académique 2025-2026.",
                'body' => "## 105 diplômés P13\n\nLa promotion 13 a soutenu ses mémoires en juin 2026. 105 auditeurs ont validé leur master, dont 12 avec la mention « Très Bien ».\n\n## Top 3 des mémoires\n\n1. *L'impact des PPP sur le développement infrastructurel au Cameroun* — note 18/20\n2. *Modernisation du contrôle fiscal à l'ère numérique* — note 17/20\n3. *Décentralisation et performance des collectivités locales* — note 17/20\n\n## Cérémonie de remise des diplômes\n\nLa cérémonie officielle aura lieu le **15 juillet 2026** au Campus de Messa, en présence du Recteur de l'Université de Yaoundé II et de représentants du MINFI.",
            ],
            [
                'slug' => 'partenariat-institut-finances-maroc',
                'category' => 'partenariat',
                'is_pinned' => false,
                'days_ago' => 50,
                'title' => 'Nouveau partenariat avec l\'Institut des Finances du Maroc',
                'excerpt' => "Accord d'échange pédagogique signé en mars 2026 avec l'Institut Basil Fuleihan.",
                'body' => "## Signature à Yaoundé\n\nLe PSSFP a accueilli en mars 2026 une délégation de l'Institut des Finances du Maroc (Basil Fuleihan) pour la signature d'un accord d'échange pédagogique.\n\n## Modalités\n\n- **MEDIAFIP étendu** au Maroc\n- **Co-organisation** d'un colloque annuel (alternance Yaoundé / Rabat)\n- **Échanges d'enseignants** dans les deux sens\n- **Co-publications** sur les finances publiques nord-africaines et subsahariennes\n\n## Premiers résultats\n\nDeux étudiants P13 sont déjà partis en mobilité Maroc-Cameroun pour leur stage M2.",
            ],
            [
                'slug' => 'communique-clarification-frais-cremincam',
                'category' => 'communique',
                'is_pinned' => false,
                'days_ago' => 7,
                'title' => 'Clarification sur les frais de candidature — paiement CREMINCAM',
                'excerpt' => 'Précisions concernant le canal officiel de paiement des 50 000 FCFA.',
                'body' => "## Information importante\n\nLe PSSFP rappelle que les frais de candidature de **50 000 FCFA** sont exclusivement payables en agence **CREMINCAM**.\n\nAucun autre canal de paiement n'est officiel à ce jour. Le PSSFP n'a aucune relation contractuelle avec d'autres opérateurs financiers concernant ces frais.\n\n## Procédure\n\n1. Soumettre sa candidature en ligne sur [candidature.pssfp.net](https://candidature.pssfp.net)\n2. Recevoir son numéro de dossier (P14026-XXX)\n3. Se rendre dans une agence CREMINCAM avec ce numéro\n4. Régler les 50 000 FCFA\n5. Conserver le récépissé bancaire pour le dépôt physique\n\nEn cas de doute, contactez l'UAAF à [admissions@pssfp.net](mailto:admissions@pssfp.net).",
            ],
            [
                'slug' => 'journee-portes-ouvertes-2026',
                'category' => 'evenement',
                'is_pinned' => false,
                'days_ago' => 14,
                'title' => 'Journée portes ouvertes 2026 — 25 mai',
                'excerpt' => "Visites du campus, rencontres avec les enseignants, témoignages d'anciens.",
                'body' => "## Une matinée d'immersion\n\nLe PSSFP organise sa journée portes ouvertes annuelle le **samedi 25 mai 2026** de 9h à 13h au Campus de Messa.\n\n## Programme\n\n- 9h00 — Accueil et présentation du programme\n- 9h30 — Visite guidée du campus (amphis, bibliothèque, salles informatiques)\n- 10h30 — Table ronde « Pourquoi choisir le PSSFP ? » avec d'anciens diplômés\n- 11h30 — Rencontres individuelles avec les responsables de spécialité\n- 12h30 — Cocktail de clôture\n\n## Inscription\n\nGratuite mais recommandée à [contact@pssfp.net](mailto:contact@pssfp.net) pour faciliter l'organisation.",
            ],
        ];

        foreach ($articles as $data) {
            Article::query()->updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'title' => ['fr' => $data['title']],
                    'excerpt' => ['fr' => $data['excerpt']],
                    'body' => ['fr' => $data['body']],
                    'category' => $data['category'],
                    'status' => Article::STATUS_PUBLISHED,
                    'published_at' => now()->subDays($data['days_ago']),
                    'is_pinned' => $data['is_pinned'],
                ],
            );
        }
    }
}
