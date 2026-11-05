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
    'bareme_defaut' => [
        'M1' => ['inscription' => 185000, 'tranche1' => 500000, 'tranche2' => 500000],
        'M2' => ['inscription' => 135000, 'tranche1' => 500000, 'tranche2' => 500000],
    ],
];
