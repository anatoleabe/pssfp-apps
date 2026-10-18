<?php

declare(strict_types=1);

namespace App\Services\Library\Import;

/**
 * Lexique de remise en casse des titres Joomla saisis TOUT EN MAJUSCULES, souvent sans
 * accents ni apostrophes (« LEXECUTION DU BUDGET DE LETAT »). Établi d'après les mots
 * les plus fréquents des 3 022 titres de l'ancienne bibliothèque (relevé du 2026-10-18).
 */
final class TitleLexicon
{
    /** Sigles conservés en majuscules (les mots sans voyelle le sont aussi d'office). */
    public const ACRONYMS = [
        'AE', 'AFD', 'AFRITAC', 'ARMP', 'BAD', 'BCEAO', 'BEAC', 'BEI', 'BID', 'BIP', 'BIRD', 'BM', 'BOAD',
        'CAB', 'CAMES', 'CEDEAO', 'CEEAC', 'CEMAC', 'CFA', 'CNPS', 'COBAC', 'CONAC', 'CONSUPE', 'COVID', 'CRTV', 'CUD',
        'DAD', 'DGB', 'DGD', 'DGFIP', 'DGI', 'DGTCFM', 'DSCE', 'DSRP', 'EPA', 'FCFA', 'FEICOM', 'FMI', 'FOAD',
        'IFAC', 'IFRS', 'INTOSAI', 'IPSAS', 'ISSAI', 'LOLF', 'LMD', 'MIGA', 'MINADER', 'MINAGRI', 'MINAS', 'MINAT',
        'MINATD', 'MINDDEVEL', 'MINDEVEL', 'MINEDUB', 'MINEE', 'MINEF', 'MINEFI', 'MINEFIB', 'MINEPAT',
        'MINESEC', 'MINESUP', 'MINFI', 'MINFIB', 'MINFOPRA', 'MINMAP', 'MINPAT', 'MINREX', 'MINSANTE', 'MINVIL',
        'NEPAD', 'OCDE', 'OHADA', 'OMC', 'ONU', 'PEFA', 'PIB', 'PM', 'PNDP', 'PNUD', 'PPP', 'PRC', 'PSSFP',
        'RCA', 'RGCP', 'SIGIPES', 'SND', 'SNH', 'SYSCOHADA', 'TOFE', 'TVA', 'UA', 'UE', 'UEAC', 'UEMOA',
    ];

    /** Noms propres, quelle que soit la langue du titre. */
    public const PROPER_NOUNS = [
        'afrique' => 'Afrique', 'africa' => 'Africa', 'african' => 'African', 'belgique' => 'Belgique',
        'benin' => 'Bénin', 'burkina' => 'Burkina', 'cameroon' => 'Cameroon', 'cameroun' => 'Cameroun',
        'canada' => 'Canada', 'congo' => 'Congo', 'douala' => 'Douala', 'europe' => 'Europe', 'faso' => 'Faso',
        'france' => 'France', 'gabon' => 'Gabon', 'kenya' => 'Kenya', 'lima' => 'Lima', 'mali' => 'Mali',
        'maroc' => 'Maroc', 'mexico' => 'Mexico', 'niger' => 'Niger', 'nigeria' => 'Nigeria', 'paris' => 'Paris',
        'quebec' => 'Québec', 'rwanda' => 'Rwanda', 'senegal' => 'Sénégal', 'suisse' => 'Suisse',
        'tchad' => 'Tchad', 'tunisie' => 'Tunisie', 'yaounde' => 'Yaoundé',
    ];

    /** Formes françaises rétablies (accents, ligatures, élisions figées, majuscules d'usage). */
    public const FRENCH_WORDS = [
        'a' => 'à', 'activite' => 'activité', 'assimiles' => 'assimilés', 'activites' => 'activités', 'administratrif' => 'administratif',
        'allege' => 'allégé', 'allouees' => 'allouées', 'asymetrique' => 'asymétrique', 'axee' => 'axée', 'amelioration' => 'amélioration', 'ameliorer' => 'améliorer',
        'amenagement' => 'aménagement', 'annee' => 'année', 'annees' => 'années', 'aout' => 'août',
        'apres' => 'après', 'arrete' => 'arrêté', 'arretes' => 'arrêtés', 'assemblee' => 'Assemblée',
        'aupres' => 'auprès', 'beneficiaire' => 'bénéficiaire', 'beneficiaires' => 'bénéficiaires',
        'budgetaire' => 'budgétaire', 'budgetaires' => 'budgétaires', 'budgetisation' => 'budgétisation',
        'caractere' => 'caractère', 'categorie' => 'catégorie', 'categories' => 'catégories', 'cle' => 'clé',
        'cles' => 'clés', 'cloture' => 'clôture', 'collectivite' => 'collectivité', 'collectivites' => 'collectivités',
        'comite' => 'comité', 'communaute' => 'communauté', 'competence' => 'compétence', 'competences' => 'compétences',
        'completant' => 'complétant', 'comptabilite' => 'comptabilité', 'consequences' => 'conséquences',
        'controle' => 'contrôle', 'controler' => 'contrôler', 'controles' => 'contrôles', 'controleur' => 'contrôleur',
        'controleurs' => 'contrôleurs', 'cooperation' => 'coopération', 'corriges' => 'corrigés', 'cout' => 'coût',
        'couts' => 'coûts', 'creance' => 'créance', 'creances' => 'créances', 'creation' => 'création', 'creations' => 'créations', 'consolidee' => 'consolidée',
        'credit' => 'crédit', 'credits' => 'crédits', 'critere' => 'critère', 'criteres' => 'critères',
        'debat' => 'débat', 'deblocage' => 'déblocage', 'debats' => 'débats', 'decembre' => 'décembre', 'decentralisation' => 'décentralisation',
        'decentralise' => 'décentralisé', 'decentralisee' => 'décentralisée', 'decentralisees' => 'décentralisées',
        'decentralises' => 'décentralisés', 'decideur' => 'décideur', 'decideurs' => 'décideurs',
        'decision' => 'décision', 'decisions' => 'décisions', 'declaration' => 'déclaration',
        'deconcentration' => 'déconcentration', 'deconcentres' => 'déconcentrés', 'decret' => 'décret',
        'decrets' => 'décrets', 'deficit' => 'déficit', 'deficits' => 'déficits', 'definition' => 'définition',
        'deja' => 'déjà', 'delai' => 'délai', 'demarche' => 'démarche', 'demarches' => 'démarches', 'delais' => 'délais', 'delegation' => 'délégation',
        'deliberation' => 'délibération', 'deliberations' => 'délibérations', 'dematerialisation' => 'dématérialisation',
        'deontologie' => 'déontologie', 'depense' => 'dépense', 'depenses' => 'dépenses', 'deplacement' => 'déplacement', 'deplacements' => 'déplacements',
        'depositaire' => 'dépositaire', 'depot' => 'dépôt', 'desequilibres' => 'déséquilibres', 'detection' => 'détection',
        'deuxieme' => 'deuxième', 'developpement' => 'développement', 'developper' => 'développer', 'dun' => "d'un", 'dune' => "d'une",
        'ecart' => 'écart', 'echeance' => 'échéance', 'echeances' => 'échéances', 'echeancier' => 'échéancier', 'ecarts' => 'écarts', 'ecole' => 'école', 'economie' => 'économie',
        'economies' => 'économies', 'economique' => 'économique', 'economiques' => 'économiques',
        'ecriture' => 'écriture', 'ecritures' => 'écritures', 'education' => 'éducation', 'efficacite' => 'efficacité',
        'egalite' => 'égalité', 'elaboration' => 'élaboration', 'elaborer' => 'élaborer', 'election' => 'élection',
        'elections' => 'élections', 'electronique' => 'électronique', 'element' => 'élément', 'elements' => 'éléments',
        'elus' => 'élus', 'emergence' => 'émergence', 'emergents' => 'émergents', 'emergentes' => 'émergentes', 'emission' => 'émission',
        'emissions' => 'émissions', 'energie' => 'énergie', 'enquete' => 'enquête', 'entite' => 'entité',
        'entites' => 'entités', 'epargne' => 'épargne', 'equilibre' => 'équilibre', 'equite' => 'équité',
        'especes' => 'espèces', 'etablissement' => 'établissement', 'etablissements' => 'établissements',
        'etape' => 'étape', 'etapes' => 'étapes', 'etat' => 'État', 'etats' => 'États', 'ethique' => 'éthique',
        'etranger' => 'étranger', 'etrangere' => 'étrangère', 'etude' => 'étude', 'etudes' => 'études',
        'europeen' => 'européen', 'europeenne' => 'européenne', 'evaluation' => 'évaluation',
        'evaluations' => 'évaluations', 'evaluer' => 'évaluer', 'evolution' => 'évolution', 'execution' => 'exécution',
        'executif' => 'exécutif', 'executoire' => 'exécutoire', 'executoires' => 'exécutoires',
        'experience' => 'expérience', 'experiences' => 'expériences', 'exoneration' => 'exonération', 'exonerations' => 'exonérations',
        'exterieur' => 'extérieur', 'exterieure' => 'extérieure', 'federal' => 'fédéral', 'federale' => 'fédérale',
        'fevrier' => 'février', 'fiabilite' => 'fiabilité', 'financiere' => 'financière', 'financieres' => 'financières',
        'fiscalite' => 'fiscalité', 'forestiere' => 'forestière', 'francais' => 'français', 'francaise' => 'française',
        'general' => 'général', 'generale' => 'générale', 'generales' => 'générales', 'generalite' => 'généralité',
        'generalites' => 'généralités', 'generaux' => 'généraux', 'generation' => 'génération', 'gerant' => 'gérant',
        'gerants' => 'gérants', 'hierarchique' => 'hiérarchique', 'hierarchise' => 'hiérarchisé',
        'hopital' => 'hôpital', 'hopitaux' => 'hôpitaux', 'immateriel' => 'immatériel',
        'immateriels' => 'immatériels', 'impot' => 'impôt', 'impots' => 'impôts',
        'indemnite' => 'indemnité', 'indemnites' => 'indemnités', 'integration' => 'intégration',
        'integrite' => 'intégrité', 'interet' => 'intérêt', 'interets' => 'intérêts', 'interieur' => 'intérieur',
        'interministeriel' => 'interministériel', 'journaliere' => 'journalière', 'legal' => 'légal', 'legale' => 'légale', 'legalite' => 'légalité',
        'legaux' => 'légaux', 'legislation' => 'législation', 'lecons' => 'leçons', 'lies' => 'liés', 'liees' => 'liées',
        'macroeconomique' => 'macroéconomique', 'macroeconomiques' => 'macroéconomiques', 'maitrise' => 'maîtrise',
        'marche' => 'marché', 'marches' => 'marchés', 'matiere' => 'matière', 'matieres' => 'matières',
        'mecanisme' => 'mécanisme', 'mecanismes' => 'mécanismes', 'memento' => 'mémento', 'mementos' => 'mémentos',
        'memoire' => 'mémoire', 'methode' => 'méthode', 'methodes' => 'méthodes', 'methodologie' => 'méthodologie',
        'methodologique' => 'méthodologique', 'miniere' => 'minière', 'ministere' => 'ministère',
        'ministeriel' => 'ministériel', 'ministerielle' => 'ministérielle', 'modalite' => 'modalité',
        'modalites' => 'modalités', 'modele' => 'modèle', 'modeles' => 'modèles', 'monetaire' => 'monétaire',
        'municipalite' => 'municipalité', 'necessite' => 'nécessité', 'negociation' => 'négociation',
        'negociations' => 'négociations', 'numerique' => 'numérique', 'numeriques' => 'numériques', 'oeuvre' => 'œuvre',
        'operation' => 'opération', 'operationnel' => 'opérationnel', 'operationnelle' => 'opérationnelle',
        'operations' => 'opérations', 'opportunite' => 'opportunité', 'periode' => 'période', 'petrolier' => 'pétrolier',
        'petroliere' => 'pétrolière', 'petroliers' => 'pétroliers', 'pieces' => 'pièces', 'precis' => 'précis',
        'precisant' => 'précisant', 'precision' => 'précision', 'prealable' => 'préalable', 'preambule' => 'préambule',
        'premiere' => 'première', 'preparation' => 'préparation', 'presentation' => 'présentation',
        'presidence' => 'présidence', 'president' => 'président', 'prevention' => 'prévention', 'prevision' => 'prévision',
        'previsions' => 'prévisions', 'previsionnel' => 'prévisionnel', 'previsionnelle' => 'prévisionnelle', 'priorite' => 'priorité', 'prive' => 'privé',
        'privee' => 'privée', 'prives' => 'privés', 'probleme' => 'problème', 'progres' => 'progrès', 'problemes' => 'problèmes',
        'procedure' => 'procédure', 'procedures' => 'procédures', 'proces' => 'procès', 'propriete' => 'propriété',
        'proprietaire' => 'propriétaire', 'proprietaires' => 'propriétaires', 'publicite' => 'publicité', 'qualite' => 'qualité',
        'realisation' => 'réalisation', 'realite' => 'réalité', 'realise' => 'réalisé', 'realises' => 'réalisés', 'recapitulatif' => 'récapitulatif',
        'redaction' => 'rédaction', 'reduction' => 'réduction', 'reference' => 'référence', 'references' => 'références',
        'referentiel' => 'référentiel', 'reflexion' => 'réflexion', 'reforme' => 'réforme', 'reformer' => 'réformer',
        'reformes' => 'réformes', 'regime' => 'régime', 'regimes' => 'régimes', 'region' => 'région',
        'regional' => 'régional', 'regionale' => 'régionale', 'regionales' => 'régionales', 'regionaux' => 'régionaux',
        'regions' => 'régions', 'regissant' => 'régissant', 'regisseur' => 'régisseur', 'regisseurs' => 'régisseurs',
        'reglant' => 'réglant', 'regle' => 'règle', 'reglement' => 'règlement', 'reglementaire' => 'réglementaire',
        'reglementation' => 'réglementation', 'reglements' => 'règlements', 'regles' => 'règles',
        'regularite' => 'régularité', 'reingenierie' => 'réingénierie', 'regulation' => 'régulation', 'reorganisation' => 'réorganisation',
        'repartition' => 'répartition', 'reponse' => 'réponse', 'reponses' => 'réponses', 'resolution' => 'résolution', 'republique' => 'République',
        'reseau' => 'réseau', 'reseaux' => 'réseaux', 'responsabilite' => 'responsabilité', 'resultat' => 'résultat',
        'resultats' => 'résultats', 'reussir' => 'réussir', 'reussite' => 'réussite', 'revision' => 'révision',
        'role' => 'rôle', 'salubrite' => 'salubrité', 'sante' => 'santé', 'securite' => 'sécurité',
        'seminaire' => 'séminaire', 'seminaires' => 'séminaires', 'senat' => 'Sénat', 'sincerite' => 'sincérité',
        'societe' => 'société', 'societes' => 'sociétés', 'soutenabilite' => 'soutenabilité', 'special' => 'spécial',
        'speciale' => 'spéciale', 'specialise' => 'spécialisé', 'specialisee' => 'spécialisée',
        'specifique' => 'spécifique', 'specifiques' => 'spécifiques', 'stabilite' => 'stabilité',
        'strategie' => 'stratégie', 'strategies' => 'stratégies', 'strategique' => 'stratégique',
        'subventionnes' => 'subventionnés', 'superieur' => 'supérieur', 'supreme' => 'suprême', 'superieure' => 'supérieure',
        'systematique' => 'systématique', 'telecommunications' => 'télécommunications', 'systeme' => 'système', 'systemes' => 'systèmes', 'theme' => 'thème',
        'themes' => 'thèmes', 'theorie' => 'théorie', 'theorique' => 'théorique', 'tirees' => 'tirées',
        'transferee' => 'transférée', 'transferees' => 'transférées', 'transferes' => 'transférés',
        'tresor' => 'Trésor', 'tresorerie' => 'trésorerie', 'tres' => 'très', 'troisieme' => 'troisième',
        'verification' => 'vérification', 'vivrieres' => 'vivrières',
    ];

    /** Mots sans accent à rétablir après une élision perdue (« LAUDIT » → « l'audit »). */
    public const ELISION_WORDS = [
        'accompagnement', 'accord', 'acheteur', 'achat', 'achats', 'acte', 'action', 'administration', 'adoption', 'afrique',
        'agence', 'agent', 'aide', 'alimentation', 'allocation', 'amortissement', 'analyse', 'application', 'appui',
        'approche', 'apurement', 'arbitrage', 'article', 'assiette', 'assurance', 'audit', 'autonomie', 'eau',
        'efficience', 'emploi', 'emprunt', 'endettement', 'engagement', 'enjeux', 'ensemble', 'entreprise',
        'entreprises', 'environnement', 'essentiel', 'exemple', 'exercice', 'exercices', 'expert', 'expertise',
        'exploitation', 'harmonisation', 'histoire', 'imposition', 'impact', 'inflation', 'information', 'innovation', 'intelligence',
        'inspection', 'institution', 'institutions', 'instruction', 'intervention', 'inventaire', 'investissement',
        'investissements', 'objectif', 'objectifs', 'offre', 'optimisation', 'ordonnateur', 'organisation', 'outil',
        'union', 'urbanisme', 'usage', 'utilisation',
    ];

    /** Mots anglais courants (langue du titre). */
    public const ENGLISH_MARKERS = [
        'the', 'of', 'and', 'for', 'in', 'on', 'to', 'with', 'from', 'by', 'an', 'at', 'into', 'its', 'is', 'are',
        'public', 'financial', 'management', 'government', 'policy', 'policies', 'report', 'review', 'development',
        'accounting', 'taxation', 'revenue', 'revenues', 'expenditure', 'countries', 'country', 'economic', 'economy',
        'sector', 'state', 'reform', 'reforms', 'control', 'world', 'bank', 'debt', 'growth', 'how', 'what',
        'accountability', 'analysis', 'annual', 'cost', 'costs', 'effective', 'fraud', 'framework', 'handbook',
        'lessons', 'toward', 'towards', 'why',
    ];

    /** Mots français courants : un seul suffit à écarter l'anglais. */
    public const FRENCH_MARKERS = [
        'le', 'la', 'les', 'de', 'des', 'du', 'et', 'en', 'un', 'une', 'au', 'aux', 'pour', 'sur', 'dans', 'par',
        'avec', 'sans', 'entre', 'est', 'ou', 'où', 'qui', 'que', 'ce', 'cette', 'ces', 'son', 'sa', 'ses', 'leur',
        'l', 'd', 'à', 'portant', 'fixant', 'relatif', 'relative', 'relatifs', 'relatives',
    ];
}
