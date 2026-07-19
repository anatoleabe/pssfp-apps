<?php

declare(strict_types=1);

/*
 * Liste de référence des établissements d'enseignement supérieur (CEMAC)
 * pour le sélecteur "Établissement de délivrance" du formulaire candidature
 * (`institut`). Groupée par pays pour un <select> avec <optgroup> côté
 * frontend. Liste volontairement non-exhaustive (grandes universités
 * publiques + quelques établissements privés reconnus en lien avec les
 * finances publiques) — le frontend propose systématiquement une option
 * "Autre" en texte libre pour tout établissement non listé ici. Le champ
 * `candidatures.institut` reste un VARCHAR libre côté validation.
 */
return [
    'Cameroun' => [
        'Université de Yaoundé I',
        'Université de Yaoundé II (Soa)',
        'Université de Douala',
        'Université de Dschang',
        'Université de Ngaoundéré',
        'Université de Buea',
        'Université de Bamenda',
        'Université de Maroua',
        'Institut Sous-régional de Statistique et d\'Économie Appliquée (ISSEA)',
        'Université Catholique d\'Afrique Centrale (UCAC)',
        'Institut Universitaire de la Côte (IUC)',
    ],
    'Tchad' => [
        'Université de N\'Djamena',
        'Université Adam Barka d\'Abéché',
    ],
    'République Centrafricaine' => [
        'Université de Bangui',
    ],
    'Gabon' => [
        'Université Omar Bongo',
        'Université des Sciences et Techniques de Masuku',
    ],
    'Guinée Équatoriale' => [
        'Universidad Nacional de Guinea Ecuatorial (UNGE)',
    ],
    'Congo' => [
        'Université Marien Ngouabi',
    ],
];
