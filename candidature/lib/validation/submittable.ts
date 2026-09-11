import type { MyCandidature } from '@/lib/api/client';
import { FALLBACK_SPECIALITES } from '@/lib/api/fallbacks';

/**
 * Reproduction TypeScript pure de CandidatureService::checkSubmittable (PR C).
 *
 * Utilisé par :
 * - <DossierCompleteness> côté UI pour activer/désactiver le bouton « Soumettre »
 * - Server Action submitDossierAction (re-validation pré-call backend)
 *
 * Doit rester aligné avec backend/app/Services/CandidatureService.php#checkSubmittable.
 */

const REQUIRED_FIELDS: ReadonlyArray<keyof MyCandidature> = [
  'civilite',
  'nom',
  'prenom',
  'date_naissance',
  'lieu_naissance',
  'genre',
  'statut_matrimonial',
  'nationalite',
  'pays_origine',
  'pays_residence',
  'adresse',
  'ville_residence',
  'indicatif1',
  'telephone1',
  'specialite',
  'type_etude',
  'premiere_langue',
  'diplome_obtenu',
  'institut',
  'specialite_diplome',
  'annee_diplome',
  'statut_actuel',
  'moyen_connaissance',
  'email',
  'engagement_nom',
];

export interface SubmittableResult {
  ok: boolean;
  missing: string[];
  errors: Record<string, string>;
}

export function checkSubmittable(c: MyCandidature): SubmittableResult {
  const missing: string[] = [];
  const errors: Record<string, string> = {};

  // La photo ne figure volontairement pas ici (ADR-0009) : elle reste
  // obligatoire pour la recevabilité du dossier, mais ne bloque plus l'acte
  // de soumettre. Le miroir de cette règle est `checkSubmittable()` côté
  // Laravel — les deux doivent rester alignés.

  for (const field of REQUIRED_FIELDS) {
    const v = c[field];
    if (v === null || v === undefined || v === '') {
      missing.push(String(field));
      errors[String(field)] = `Champ obligatoire manquant : ${String(field)}.`;
    }
  }

  if (typeof c.annee_diplome === 'number' && c.annee_diplome > new Date().getFullYear()) {
    errors.annee_diplome = "L'année du diplôme ne peut pas être dans le futur.";
  }

  if (c.date_naissance && typeof c.annee_diplome === 'number') {
    const birthYear = new Date(c.date_naissance).getUTCFullYear();
    if (c.annee_diplome - birthYear < 18) {
      errors.annee_diplome = "L'écart entre la date de naissance et l'année du diplôme doit être d'au moins 18 ans.";
    }
  }

  if (c.pays_residence === 'CM') {
    if (!c.region) {
      errors.region = 'La région est obligatoire pour un candidat résidant au Cameroun.';
    }
    if (!c.departement) {
      errors.departement = 'Le département est obligatoire pour un candidat résidant au Cameroun.';
    }
  }

  if (c.statut_actuel && !['Etudiant', 'Sans-emploi'].includes(c.statut_actuel)) {
    if (!c.employeur) {
      errors.employeur = 'L’employeur ou l’organisation est obligatoire.';
    }
    if (!c.fonction_actuelle) {
      errors.fonction_actuelle = 'La fonction ou le poste occupé est obligatoire.';
    }
  }

  const sourcesWithDetail = [
    'Autre', 'Autre réseau social', 'Administration ou employeur',
    'Université ou établissement d’enseignement',
    'Collègue, ami ou membre de la famille',
  ];
  if (c.moyen_connaissance && sourcesWithDetail.includes(c.moyen_connaissance) && !c.moyen_connaissance_detail) {
    errors.moyen_connaissance_detail = 'Veuillez préciser comment vous avez connu le PSSFP.';
  }

  // Miroir de CandidatureService::checkDiplomeRequis — n'applique les nouvelles
  // exigences qu'aux dossiers créés après la mise en production d'août 2026.
  if ((c.form_version ?? 1) >= 2) {
    const requiredV2: ReadonlyArray<[keyof MyCandidature, string]> = [
      ['diplome_requis', 'Le diplôme requis est obligatoire.'],
      ['annee_diplome_requis', "L'année d'obtention du diplôme requis est obligatoire."],
      ['domaine_diplome_requis', 'Le domaine du diplôme requis est obligatoire.'],
      ['institut_diplome_requis', "L'établissement de délivrance du diplôme requis est obligatoire."],
    ];

    for (const [field, message] of requiredV2) {
      const v = c[field];
      if (v === null || v === undefined || v === '') {
        missing.push(String(field));
        errors[String(field)] = message;
      }
    }

    if (c.domaine_diplome_requis === 'autres' && !c.specialite_diplome_requis) {
      missing.push('specialite_diplome_requis');
      errors.specialite_diplome_requis =
        'La spécialité du diplôme requis est obligatoire lorsque le domaine est « Autres ».';
    }

    (c.autres_diplomes ?? []).forEach((row, index) => {
      if (!row.intitule || !row.etablissement || !row.annee) {
        errors[`autres_diplomes.${index}`] =
          'Chaque diplôme complémentaire ajouté doit indiquer son intitulé, son établissement et son année.';
      }
    });

    (c.formations_professionnelles ?? []).forEach((row, index) => {
      if (!row.centre || !row.qualification || !row.annee) {
        errors[`formations_professionnelles.${index}`] =
          'Chaque formation professionnelle ajoutée doit indiquer son centre, sa qualification et son année.';
      }
    });
  }

  const allowedSpecialiteLabels = new Set(FALLBACK_SPECIALITES.map((s) => s.label));
  if (c.specialite && !allowedSpecialiteLabels.has(c.specialite)) {
    // Tolérant : si le backend a une liste plus longue, on ne bloque pas. Le
    // submit backend re-valide de toute façon (défense en profondeur).
  }

  return { ok: missing.length === 0 && Object.keys(errors).length === 0, missing, errors };
}
