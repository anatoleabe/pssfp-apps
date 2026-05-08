<?php

declare(strict_types=1);

/*
 * Liste de PIN à 6 chiffres interdits à l'inscription candidat (ADR-0007).
 *
 * Source : statistiques agrégées Have I Been Pwned + études Symantec/Datagenetics
 * sur les PIN les plus courants. La présence dans cette liste vaut rejet
 * immédiat sans message ambigu côté UX.
 *
 * À étendre si la veille sécu fait remonter de nouveaux patterns triviaux.
 */
return [
    // Suites évidentes
    '123456', '654321', '012345', '543210',
    '111111', '000000', '222222', '333333', '444444',
    '555555', '666666', '777777', '888888', '999999',

    // Patterns répétitifs
    '123123', '121212', '123321', '132132', '112233',
    '101010', '010101', '202020',

    // Combinaisons banales constatées
    '159753', '159357', '147258', '258369',
    '789456', '147147', '321321', '456789',

    // Années récentes (souvent choisies comme PIN)
    '202020', '202120', '202220', '202320', '202420',
    '202520', '202620', '202720',

    // Anniversaires partiels génériques
    '010100', '010101', '010199', '010180', '010190',
    '311299', '311299',

    // Lookalikes / faux aléatoire
    '777111', '111777', '555111', '999000',
];
