<?php

declare(strict_types=1);

/*
 * Valeurs fixes des fichiers d'import des retenus, reprises du classeur de la
 * 13e promotion (« Liste des candidats retenus … + MATRICULES »).
 *
 * - acma : colonnes constantes de l'onglet « IMPORTATION ACMA » (logiciel de
 *   scolarité). Session laissée vide en P13.
 * - moodle : colonne « department » des comptes de la plateforme FOAD ; la
 *   cohorte, propre à chaque promotion, se saisit au téléchargement.
 */
return [
    'acma' => [
        'exp' => 'FR',
        'parcours' => 'MFP',
        'categorie' => 'TC',
        'session' => '',
    ],

    'moodle' => [
        'department' => 'PSSFP',
    ],
];
