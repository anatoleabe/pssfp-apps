<?php

declare(strict_types=1);

/*
 * Correspondances de l'ancienne bibliothèque Joomla (DOCman + Alexandria) vers la
 * bibliothèque V1 — plan 3, task 3 (docs/plans/2026-10-18-m3-biblio-plan3-import-joomla.md).
 * Lu par App\Services\Library\Import\LegacyMapping, qui valide tout au chargement.
 *
 * Règles : la clé est un chemin de catégorie (slugs séparés par « / ») et s'applique à ce
 * chemin et à toutes ses sous-catégories. Pour un chemin donné, toutes les règles qui en
 * sont un préfixe (par segment) sont combinées de la plus générale à la plus spécifique :
 *   - `type` : celui de la règle la plus spécifique qui en déclare un ;
 *   - `access_level` : idem, sinon `default_access_level` ;
 *   - `keywords` : union, dans l'ordre, sans doublon (le dernier est le plus spécifique).
 * La clé '' (racine Alexandria) ne s'applique qu'à la racine elle-même.
 * Un chemin qu'aucune règle typée ne couvre lève UnmappedLegacyCategory.
 */
return [
    // Aucune catégorie réservée en V1 (décision du 2026-10-18) ; aucun prix.
    'default_access_level' => 'public',

    /*
     * Thésaurus créé par l'import (firstOrCreate par slug). Un parent est toujours
     * déclaré avant ses enfants. Slugs ≤ 120 caractères (colonne mots_cles.slug).
     */
    'thesaurus' => [
        'themes' => ['label' => 'Thèmes'],
        'institutions' => ['label' => 'Institutions'],
        'types-de-textes' => ['label' => 'Types de textes'],

        // Thèmes
        'audit-et-controle' => ['label' => 'Audit et contrôle', 'parent' => 'themes'],
        'budget-programme' => ['label' => 'Budget-programme', 'parent' => 'themes'],
        'comptabilite-publique' => ['label' => 'Comptabilité publique', 'parent' => 'themes'],
        'decentralisation' => ['label' => 'Décentralisation', 'parent' => 'themes'],
        'economie' => ['label' => 'Économie', 'parent' => 'themes'],
        'economie-publique' => ['label' => 'Économie publique', 'parent' => 'themes'],
        'finances-publiques' => ['label' => 'Finances publiques', 'parent' => 'themes'],
        'finances-publiques-locales' => ['label' => 'Finances publiques locales', 'parent' => 'themes'],
        'fiscalite' => ['label' => 'Fiscalité', 'parent' => 'themes'],
        'generalites' => ['label' => 'Généralités', 'parent' => 'themes'],
        'gestion-budgetaire' => ['label' => 'Gestion budgétaire', 'parent' => 'themes'],
        'lois-de-reglement' => ['label' => 'Lois de règlement', 'parent' => 'themes'],
        'management-public' => ['label' => 'Management public', 'parent' => 'themes'],
        'marches-publics' => ['label' => 'Marchés publics', 'parent' => 'themes'],
        'politiques-publiques' => ['label' => 'Politiques publiques', 'parent' => 'themes'],
        'recueils-de-textes' => ['label' => 'Recueils de textes financiers', 'parent' => 'themes'],
        'reformes' => ['label' => 'Réformes', 'parent' => 'themes'],

        // Institutions
        'institutions-internationales' => ['label' => 'Institutions internationales', 'parent' => 'institutions'],
        'institutions-nationales' => ['label' => 'Institutions nationales', 'parent' => 'institutions'],
        'bad' => ['label' => 'Banque africaine de développement (BAD)', 'parent' => 'institutions-internationales'],
        'banque-mondiale' => ['label' => 'Banque mondiale', 'parent' => 'institutions-internationales'],
        'beac' => ['label' => 'Banque des États de l\'Afrique centrale (BEAC)', 'parent' => 'institutions-internationales'],
        'cemac' => ['label' => 'CEMAC', 'parent' => 'institutions-internationales'],
        'fmi' => ['label' => 'Fonds monétaire international (FMI)', 'parent' => 'institutions-internationales'],
        'assemblee-nationale' => ['label' => 'Assemblée nationale', 'parent' => 'institutions-nationales'],
        'chambre-des-comptes' => ['label' => 'Chambre des comptes', 'parent' => 'institutions-nationales'],
        'etablissements-publics-administratifs' => ['label' => 'Établissements publics administratifs', 'parent' => 'institutions-nationales'],
        'minepat' => ['label' => 'Ministère de l\'Économie, de la Planification et de l\'Aménagement du territoire (MINEPAT)', 'parent' => 'institutions-nationales'],
        'minfi' => ['label' => 'Ministère des Finances (MINFI)', 'parent' => 'institutions-nationales'],
        'prc' => ['label' => 'Présidence de la République (PRC)', 'parent' => 'institutions-nationales'],

        // Types de textes
        'arretes' => ['label' => 'Arrêtés', 'parent' => 'types-de-textes'],
        'autres-textes' => ['label' => 'Autres textes', 'parent' => 'types-de-textes'],
        'circulaires' => ['label' => 'Circulaires', 'parent' => 'types-de-textes'],
        'constitution' => ['label' => 'Constitution', 'parent' => 'types-de-textes'],
        'decisions' => ['label' => 'Décisions', 'parent' => 'types-de-textes'],
        'decrets' => ['label' => 'Décrets', 'parent' => 'types-de-textes'],
        'directives' => ['label' => 'Directives', 'parent' => 'types-de-textes'],
        'instructions' => ['label' => 'Instructions', 'parent' => 'types-de-textes'],
        'lois' => ['label' => 'Lois', 'parent' => 'types-de-textes'],
        'ordonnances' => ['label' => 'Ordonnances', 'parent' => 'types-de-textes'],
    ],

    // Chemins DOCman (oe179_docman_categories, chemin résolu par l'extraction).
    'docman' => [
        // Législation
        'textes-et-lois' => ['type' => 'texte_loi'],
        'textes-et-lois/arretes' => ['keywords' => ['arretes']],
        'textes-et-lois/arretes/arrete-comptabilite' => ['keywords' => ['comptabilite-publique']],
        'textes-et-lois/arretes/arrete-fiscalite' => ['keywords' => ['fiscalite']],
        'textes-et-lois/arretes/arretes-ctd' => ['keywords' => ['decentralisation']],
        'textes-et-lois/arretes/arretes-generalite' => ['keywords' => ['generalites']],
        'textes-et-lois/arretes/arretes-marches-public' => ['keywords' => ['marches-publics']],
        'textes-et-lois/circulaires' => ['keywords' => ['circulaires']],
        'textes-et-lois/circulaires/circulaires-comptabilite' => ['keywords' => ['comptabilite-publique']],
        'textes-et-lois/circulaires/circulaires-ctd' => ['keywords' => ['decentralisation']],
        'textes-et-lois/circulaires/circulaires-fiscalite' => ['keywords' => ['fiscalite']],
        'textes-et-lois/circulaires/circulaires-generalite' => ['keywords' => ['generalites']],
        'textes-et-lois/circulaires/circulaires-lois-de-reglement' => ['keywords' => ['lois-de-reglement']],
        'textes-et-lois/circulaires/circulaires-marches-publics' => ['keywords' => ['marches-publics']],
        'textes-et-lois/constitution' => ['keywords' => ['constitution']],
        'textes-et-lois/decisions' => ['keywords' => ['decisions']],
        'textes-et-lois/decisions/decisions-comptabilite' => ['keywords' => ['comptabilite-publique']],
        'textes-et-lois/decisions/decisions-ctd' => ['keywords' => ['decentralisation']],
        'textes-et-lois/decisions/decisions-fiscalite' => ['keywords' => ['fiscalite']],
        'textes-et-lois/decisions/decisions-generalites' => ['keywords' => ['generalites']],
        'textes-et-lois/decisions/decisions-marche-publics' => ['keywords' => ['marches-publics']],
        'textes-et-lois/decrets' => ['keywords' => ['decrets']],
        'textes-et-lois/decrets/decrets-comptabilite' => ['keywords' => ['comptabilite-publique']],
        'textes-et-lois/decrets/decrets-ctd' => ['keywords' => ['decentralisation']],
        'textes-et-lois/decrets/decrets-fiscalite' => ['keywords' => ['fiscalite']],
        'textes-et-lois/decrets/decrets-generalites' => ['keywords' => ['generalites']],
        'textes-et-lois/decrets/decrets-marches-publics' => ['keywords' => ['marches-publics']],
        'textes-et-lois/directives' => ['keywords' => ['directives']],
        'textes-et-lois/instructions-2' => ['keywords' => ['instructions']],
        'textes-et-lois/lois' => ['keywords' => ['lois']],
        'textes-et-lois/lois/lois-comptabilite' => ['keywords' => ['comptabilite-publique']],
        'textes-et-lois/lois/lois-de-reglement-et-ordonnances' => ['keywords' => ['lois-de-reglement', 'ordonnances']],
        'textes-et-lois/lois/lois-des-ctd' => ['keywords' => ['decentralisation']],
        'textes-et-lois/lois/lois-des-marches-publics' => ['keywords' => ['marches-publics']],
        'textes-et-lois/lois/lois-fiscales' => ['keywords' => ['fiscalite']],
        'textes-et-lois/lois/lois-generale' => ['keywords' => ['generalites']],
        'textes-et-lois/ordonnances' => ['keywords' => ['ordonnances']],

        'les-collectivites-territoriales-decentralisees' => ['type' => 'texte_loi', 'keywords' => ['decentralisation']],
        'les-collectivites-territoriales-decentralisees/autres-ctd' => ['keywords' => ['autres-textes']],
        'les-collectivites-territoriales-decentralisees/circulaires-1' => ['keywords' => ['circulaires']],
        'les-collectivites-territoriales-decentralisees/circulaires-2' => ['keywords' => ['circulaires']],
        'les-collectivites-territoriales-decentralisees/decisions-1' => ['keywords' => ['decisions']],
        'les-collectivites-territoriales-decentralisees/instructions-1' => ['keywords' => ['instructions']],
        'les-collectivites-territoriales-decentralisees/les-arretes' => ['keywords' => ['arretes']],
        'les-collectivites-territoriales-decentralisees/les-decrets' => ['keywords' => ['decrets']],
        'les-collectivites-territoriales-decentralisees/les-lois-de-la-decentralisation' => ['keywords' => ['lois']],

        'instructions' => ['type' => 'texte_loi', 'keywords' => ['instructions']],

        // Articles
        'articles' => ['type' => 'article_scientifique'],
        'articles/comptabilite-1' => ['keywords' => ['comptabilite-publique']],
        'articles/ctd' => ['keywords' => ['decentralisation']],
        'articles/economie' => ['keywords' => ['economie']],
        'articles/fiscalite-1' => ['keywords' => ['fiscalite']],
        'articles/gestion-budgetaire' => ['keywords' => ['gestion-budgetaire']],
        'articles/management' => ['keywords' => ['management-public']],
        'articles/marches-publics' => ['keywords' => ['marches-publics']],

        // Ouvrages
        'manuels' => ['type' => 'ouvrage_numerique'],
        'mementos' => ['type' => 'ouvrage_numerique'],
        'guides' => ['type' => 'ouvrage_numerique'],

        // Réformes, institutions, rapports
        'reformes' => ['type' => 'rapport', 'keywords' => ['reformes']],
        'reformes/audit-et-controle' => ['keywords' => ['audit-et-controle']],
        'reformes/budget-programme' => ['keywords' => ['budget-programme']],
        'reformes/comptabilite' => ['keywords' => ['comptabilite-publique']],
        'reformes/finance-publique' => ['keywords' => ['finances-publiques']],
        'reformes/seminaires-et-formations' => ['type' => 'ressource_pedagogique'],

        'institutions' => ['type' => 'rapport'],
        'institutions/institutions-internationales' => ['keywords' => ['institutions-internationales']],
        'institutions/institutions-internationales/banque-africaine-de-developpement' => ['keywords' => ['bad']],
        'institutions/institutions-internationales/banque-des-etats-de-l-afrique-centrale' => ['keywords' => ['beac']],
        'institutions/institutions-internationales/banque-mondiale' => ['keywords' => ['banque-mondiale']],
        'institutions/institutions-internationales/cemac' => ['keywords' => ['cemac']],
        'institutions/institutions-internationales/fonds-monetaire-internationale' => ['keywords' => ['fmi']],
        'institutions/institutions-nationales' => ['keywords' => ['institutions-nationales']],
        'institutions/institutions-nationales/assemblee-nationale' => ['keywords' => ['assemblee-nationale']],
        'institutions/institutions-nationales/chambre-des-comptes' => ['keywords' => ['chambre-des-comptes']],
        'institutions/institutions-nationales/etablissements-publics-administratifs' => ['keywords' => ['etablissements-publics-administratifs']],
        'institutions/institutions-nationales/minepat' => ['keywords' => ['minepat']],
        'institutions/institutions-nationales/minfi' => ['keywords' => ['minfi']],
        'institutions/institutions-nationales/prc' => ['keywords' => ['prc']],

        'rapports-et-publications' => ['type' => 'rapport'],
        // Malgré son intitulé, « Autres textes » ne contient pas de législation (relevé du
        // 2026-10-18 : guides, manuels et supports sur le contrôle et l'audit).
        'autres-textes' => ['type' => 'rapport', 'keywords' => ['audit-et-controle']],
        'economie-1' => ['type' => 'rapport', 'keywords' => ['economie']],
        'fiscalite' => ['type' => 'rapport', 'keywords' => ['fiscalite']],
    ],

    // Chemins Alexandria (oe179_abcategories, racine « ROOT » exclue du chemin).
    'alexandria' => [
        '' => ['type' => 'ouvrage_numerique'],

        'ouvrage-numerique' => ['type' => 'ouvrage_numerique'],
        'ouvrage-numerique/audit-et-controle' => ['keywords' => ['audit-et-controle']],
        'ouvrage-numerique/comptabilite-publiques' => ['keywords' => ['comptabilite-publique']],
        'ouvrage-numerique/economie-publique' => ['keywords' => ['economie-publique']],
        'ouvrage-numerique/finances' => ['keywords' => ['finances-publiques']],
        'ouvrage-numerique/finances-publiques-locales' => ['keywords' => ['finances-publiques-locales']],
        'ouvrage-numerique/fiscalite' => ['keywords' => ['fiscalite']],
        'ouvrage-numerique/gestion-budgetaire' => ['keywords' => ['gestion-budgetaire']],
        'ouvrage-numerique/marches-publics' => ['keywords' => ['marches-publics']],
        'ouvrage-numerique/politiques-publiques' => ['keywords' => ['politiques-publiques']],
        'ouvrage-numerique/receuil-de-textes' => ['keywords' => ['recueils-de-textes']],

        'reformes' => ['type' => 'rapport', 'keywords' => ['reformes']],
        'reformes/audit-et-controle' => ['keywords' => ['audit-et-controle']],
        'reformes/budget-programme' => ['keywords' => ['budget-programme']],
        'reformes/comptabilite' => ['keywords' => ['comptabilite-publique']],
        'reformes/marches-publics' => ['keywords' => ['marches-publics']],

        'rapports-et-publications' => ['type' => 'rapport'],

        // Catégorie dépubliée dans Joomla ; ses 9 livres sont des ouvrages, pas de la législation.
        'textes-et-lois' => ['type' => 'ouvrage_numerique'],
    ],
];
