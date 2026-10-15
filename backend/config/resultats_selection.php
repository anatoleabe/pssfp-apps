<?php

declare(strict_types=1);

/*
 * Documents de fin de sélection : communiqué conjoint MINFI / UY2 des
 * résultats et procès-verbal des délibérations du jury.
 *
 * Valeurs reprises des modèles Word de la scolarité (13e promotion). Ce qui
 * change d'une année à l'autre — composition du jury, secrétariat technique —
 * se met à jour ici ; ce qui change d'une séance à l'autre (date, heure, note
 * de service, numéros) se saisit au moment du téléchargement dans l'admin.
 *
 * `role` détermine la place du membre dans le bloc de signatures du PV :
 * president | vice_president_1 | vice_president_2 | rapporteur | membre.
 */
return [
    'jury' => [
        ['nom' => 'Pr AVOM Désiré', 'qualite' => 'Président', 'role' => 'president',
            'fonction' => 'Doyen de la Faculté des Sciences Economiques et de Gestion de l’Université de Yaoundé II –Soa'],
        ['nom' => 'Pr MOUNGOU MBENDA Sabine Patricia', 'qualite' => '1er Vice-président', 'role' => 'vice_president_1',
            'fonction' => 'Vice-doyen à la Faculté des Sciences Economiques et de Gestion de l’Université de Yaoundé II –Soa'],
        ['nom' => 'Dr BASAHAG Achile Nestor', 'qualite' => '2e Vice-président', 'role' => 'vice_president_2',
            'fonction' => 'Président du Comité de Pilotage'],
        ['nom' => 'Mme NDENDE Caroline', 'qualite' => 'Rapporteur', 'role' => 'rapporteur',
            'fonction' => 'Vice-présidente du Comité Scientifique'],
        ['nom' => 'Pr NGUENDA ANYA Saturnin', 'qualite' => 'Membre', 'role' => 'membre',
            'fonction' => 'Vice-doyen à la Faculté des Sciences Economiques et de Gestion de l’Université de Yaoundé II –Soa'],
        ['nom' => 'Pr ATANGANA ONDOA Henri', 'qualite' => 'Membre', 'role' => 'membre',
            'fonction' => 'Vice-doyen à la Faculté des Sciences Economiques et de Gestion de l’Université de Yaoundé II –Soa'],
        ['nom' => 'Pr TCHITCHOUA Jean', 'qualite' => 'Membre', 'role' => 'membre',
            'fonction' => 'Enseignant à l’UYII-Soa'],
        ['nom' => 'Dr BIKOAH Robert', 'qualite' => 'Membre', 'role' => 'membre',
            'fonction' => 'Enseignant à l’UYII-Soa'],
        ['nom' => 'Hon. MBARGA ASSEMBE Luc Roger', 'qualite' => 'Membre', 'role' => 'membre',
            'fonction' => 'Chef de Département GTFPL'],
        ['nom' => 'M. MBA Pierre', 'qualite' => 'Membre', 'role' => 'membre',
            'fonction' => 'C/UAAF'],
        ['nom' => 'M. MBIANA Jean Paul', 'qualite' => 'Membre', 'role' => 'membre',
            'fonction' => 'C/UPAAS'],
        ['nom' => 'M. ABE ETOUMOU Anatole', 'qualite' => 'Membre', 'role' => 'membre',
            'fonction' => 'C/USI'],
    ],

    'secretariat_technique' => 'M. MBIANA Jean Paul, Chef de l’Unité de la Programmation des Activités Académiques et de la Scolarité (UPAAS)',

    'criteres' => [
        'Conformité du dossier des candidats ;',
        'Performances académiques et professionnelles des candidats ;',
        'Motivation du candidat ;',
        'Capacité financière du candidat ;',
        'Genre ;',
        'Equilibre géographique ;',
        'Equilibre administratif ;',
        'Expérience.',
    ],

    /* Suffixes des numéros d'enregistrement (le préfixe « N°AA/… » est calculé). */
    'reference_pv' => 'PV/MINFI/PSSFP/PCS/USI/UPAAS/AT',
    'reference_communique' => 'MINFI/SG',

    /* Ordre des lignes des tableaux de répartition, comme dans les PV précédents. */
    'ordre_specialites' => [
        'economie-publique-gestion-publique',
        'audit-controle-finances-publiques',
        'gouvernance-territoriale-finances-publiques-locales',
        'fiscalite-finance-comptabilite-publique',
        'marches-publics-partenariats-public-prive',
    ],

    /*
     * Intitulés hors liste (anciens libellés) : rattachés à la première
     * spécialité dont le mot-clé apparaît, sinon à la spécialité par défaut.
     * Mots-clés comparés sans accents ni majuscules.
     */
    'mots_cles_specialites' => [
        'audit' => 'audit-controle-finances-publiques',
        'fiscal' => 'fiscalite-finance-comptabilite-publique',
        'gouvernance' => 'gouvernance-territoriale-finances-publiques-locales',
        'territorial' => 'gouvernance-territoriale-finances-publiques-locales',
        'marche' => 'marches-publics-partenariats-public-prive',
        'partenariat' => 'marches-publics-partenariats-public-prive',
        'econom' => 'economie-publique-gestion-publique',
        // Mots plus généraux, essayés après les précédents.
        'controle' => 'audit-controle-finances-publiques',
        'comptab' => 'fiscalite-finance-comptabilite-publique',
        'locale' => 'gouvernance-territoriale-finances-publiques-locales',
    ],
    'specialite_par_defaut' => 'marches-publics-partenariats-public-prive',

    /* Sigles des spécialités (colonnes étroites du PV, liste Excel). */
    'sigles_specialites' => [
        'economie-publique-gestion-publique' => 'EPGP',
        'audit-controle-finances-publiques' => 'AC',
        'gouvernance-territoriale-finances-publiques-locales' => 'GTFPL',
        'fiscalite-finance-comptabilite-publique' => 'FFCP',
        'marches-publics-partenariats-public-prive' => 'MPPPP',
    ],

    /* Statuts actuels comptés comme « secteur public » dans la synthèse du PV. */
    'statuts_secteur_public' => [
        'Fonctionnaire', 'Contractuel-Etat', 'Etablissement-public', 'Entreprise-publique', 'Fonctionnaire-Contractuel',
    ],

    /*
     * En-tête du communiqué conjoint, repris du communiqué de la 13e promotion :
     * [texte, taille en points]. Garamond gras ; l'adresse web n'est pas en gras.
     */
    'entete_universite' => [
        ['MINISTERE DE L’ENSEIGNEMENT SUPERIEUR', 9],
        ['--------------', 9],
        ['UNIVERSITE DE YAOUNDE II', 9],
        ['--------------', 9],
        ['FACULTE DES SCIENCES', 9],
        ['ECONOMIQUES ET DE GESTION', 9],
        ['B.P. : 1365 –YAOUNDE', 9],
        ['CAMEROUN', 9],
        ['www.univ-yde2.org', 9],
    ],

    'entete_minfi' => [
        ['MINISTRY OF FINANCE', 10],
        ['--------------', 9],
        ['GENERAL SECRETARIAT', 10],
        ['--------------', 9],
        ['ADVANCED PROGRAM OF', 9],
        ['SPECIALISATION IN PUBLIC FINANCE', 9],
    ],

    'pied_de_page' => [
        'B.P. : 2332 Yaoundé - Messa  Tél. : + (237) 22 22 76 81 / 697 92 13 32 / 677 25 72 72',
        'Web : www.pfinancespubliques.org - e-mail : info@pfinancespubliques.org',
    ],
];
