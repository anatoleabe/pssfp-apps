<?php

declare(strict_types=1);

/*
 * Situation professionnelle actuelle du candidat (`candidatures.statut_actuel`) :
 * valeur stockée => libellé. Utilisé par l'admin Filament, l'e-mail au comité,
 * le PV du jury et la liste Excel. La validation (UpdateCandidatureRequest)
 * accepte les mêmes valeurs. Le récépissé PDF garde volontairement ses propres
 * libellés : le modifier changerait des documents déjà remis aux candidats.
 *
 * `Fonctionnaire-Contractuel` ne se saisit plus : il subsiste sur d'anciens
 * dossiers repris de l'ancien formulaire.
 */
return [
    'Etudiant' => 'Étudiant(e)',
    'Sans-emploi' => 'Sans emploi / en recherche d’emploi',
    'Fonctionnaire' => 'Fonctionnaire titulaire',
    'Contractuel-Etat' => 'Agent contractuel de l’État',
    'Etablissement-public' => 'Agent d’un établissement public',
    'Entreprise-publique' => 'Salarié(e) d’une entreprise publique',
    'Prive' => 'Salarié(e) du secteur privé',
    'Independant' => 'Indépendant(e) / profession libérale',
    'ONG-International' => 'ONG / organisation internationale',
    'Autre' => 'Autre situation professionnelle',
    'Fonctionnaire-Contractuel' => 'Fonctionnaire / Contractuel (ancien dossier)',
];
