<?php

declare(strict_types=1);

/*
 * Scolarité des auditeurs (spec module 4, lot 1).
 *
 * Les barèmes se règlent dans l'admin (Vie académique > Barèmes). Les valeurs
 * ci-dessous ne servent qu'à pré-remplir ceux d'une année qui n'en a pas encore,
 * lors de la reprise de l'existant : M1 reprend en priorité les montants saisis
 * sur la campagne (lettre d'admission).
 */
return [
    /*
     * Année en cours (spec de la réinscription des anciens, §6.1) : seule sa promotion reçoit un nouvel auditeur et
     * des matricules attribués par la plateforme. Le réglage applicatif `scolarite.annee_en_cours` l'emporte.
     * Vide : aucune garde de promotion (réservé aux tests qui ne s'en occupent pas).
     */
    'annee_en_cours' => env('SCOLARITE_ANNEE_EN_COURS', '2026-2027'),

    // Lignes exportées au plus en un fichier Excel (espace Finances, décision du 16/11/2026).
    'export_max' => (int) env('SCOLARITE_EXPORT_MAX', 1000),

    'bareme_defaut' => [
        'M1' => ['inscription' => 185000, 'tranche1' => 500000, 'tranche2' => 500000],
        'M2' => ['inscription' => 135000, 'tranche1' => 500000, 'tranche2' => 500000],
    ],
];
