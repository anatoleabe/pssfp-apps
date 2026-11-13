/** Lien court de la lettre d'accès FOAD (SMS) : règles partagées par la page et son relais. */

/** 16 caractères aléatoires, comme les émet l'API (LiensLettreAcces::LONGUEUR_JETON). */
export const JETON_LIEN_LETTRE = /^[A-Za-z0-9]{16}$/;

export const MOTIFS_ERREUR_LIEN_LETTRE = ['date', 'format', 'verrouille', 'invalide', 'non_prete', 'limite', 'indisponible'] as const;

export type MotifErreurLienLettre = (typeof MOTIFS_ERREUR_LIEN_LETTRE)[number];

/** Motif lu dans l'adresse, ou null s'il n'est pas l'un des motifs connus. */
export function motifErreurLienLettre(valeur: string | undefined): MotifErreurLienLettre | null {
  return (MOTIFS_ERREUR_LIEN_LETTRE as readonly string[]).includes(valeur ?? '') ? (valeur as MotifErreurLienLettre) : null;
}

/** Motif affiché d'après la réponse de l'API à l'envoi de la date de naissance. */
export function motifDepuisReponse(status: number, code: string | undefined): MotifErreurLienLettre {
  if (status === 422) return code === 'date_incorrecte' ? 'date' : 'format';
  if (status === 423) return 'verrouille';
  if (status === 410) return 'invalide';
  if (status === 409) return 'non_prete';
  if (status === 429) return 'limite';

  return 'indisponible';
}
