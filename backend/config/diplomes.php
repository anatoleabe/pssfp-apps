<?php

declare(strict_types=1);

/*
 * Liste de référence des types de diplôme pour le sélecteur du formulaire
 * candidature (`diplome_obtenu`). Utilisée uniquement pour peupler la liste
 * déroulante frontend via GET /v1/reference/diplomes — le champ
 * `candidatures.diplome_obtenu` reste un VARCHAR libre côté validation
 * (UpdateCandidatureRequest) car le frontend propose une option "Autre" en
 * texte libre pour les diplômes non listés ici.
 */
return [
    'baccalaureat' => 'Baccalauréat',
    'bts-dut' => 'BTS / DUT',
    'licence' => 'Licence',
    'licence-professionnelle' => 'Licence professionnelle',
    'maitrise' => 'Maîtrise',
    'master' => 'Master',
    'ingenieur' => 'Diplôme d\'Ingénieur',
    'doctorat' => 'Doctorat',
];
