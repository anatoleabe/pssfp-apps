<?php

declare(strict_types=1);

/*
 * Documents remis aux auditeurs retenus : informations d'accès à la
 * plateforme de formation à distance (FOAD) et lettre d'admission.
 *
 * Textes repris des modèles de la scolarité (accès FOAD de la 12e promotion,
 * lettre d'admission de la 13e). Ce qui change d'une promotion à l'autre
 * (références, dates, rentrée, frais) se saisit dans l'admin, sur la campagne
 * (champ `parametres_lettres`) ; les valeurs ci-dessous n'en sont que les
 * valeurs par défaut.
 */
return [
    'signataire' => 'Le Président du Comité de Pilotage',

    'pied_de_page' => 'Programme Supérieur de Spécialisation en Finances Publiques · B.P. 2332 Yaoundé – Messa · www.pssfp.org · admissions@pssfp.org',

    'defauts' => [
        // « {numero} » : rang du matricule dans la promotion.
        'reference_acces' => 'N° {annee}/ {numero} L/MINFI/SG/PSSFP/PCP/USI',
        'reference_admission' => 'N° {annee}/ {numero} L/MINFI/SG/PSSFP/PCP/UAAF',
        'rentree_heure' => '8 heures',
        'frais_inscription_montant' => 185000,
        'frais_inscription_echeance' => 'Dès réception de la notification (avant la rentrée)',
        'frais_tranche1_montant' => 500000,
        'frais_tranche2_montant' => 500000,
        'copies' => ['Doyen/FSEG', 'Intéressé(e)', 'Archives/chrono'],
    ],
];
