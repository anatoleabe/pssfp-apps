import type { MyCandidature } from '@/lib/api/client';

/**
 * Où en est le dossier, du point de vue du candidat.
 *
 * - `constitution` : campagne ouverte, dossier encore modifiable ou en attente.
 * - `en_examen`    : campagne close, dossier soumis, décision pas encore rendue.
 * - `non_soumis`   : campagne close sans que le dossier ait été soumis.
 * - `retenu` / `non_retenu` : décision rendue.
 * - `retire`       : retiré par le candidat ou l'administration.
 */
export type DossierPhase =
  | 'constitution'
  | 'en_examen'
  | 'non_soumis'
  | 'retenu'
  | 'non_retenu'
  | 'retire';

type PhaseInput = Pick<MyCandidature, 'statut' | 'withdrawn_at' | 'campagne'>;

export function getDossierPhase(candidature: PhaseInput): DossierPhase {
  if (candidature.withdrawn_at !== null) return 'retire';
  if (candidature.statut === 'accepte') return 'retenu';
  if (candidature.statut === 'refuse') return 'non_retenu';

  // Sans campagne chargée, on considère le dossier ouvert : c'est l'état le
  // plus prudent, qui laisse l'API arbitrer chaque modification.
  const campagneOuverte = candidature.campagne?.is_currently_open ?? true;
  if (campagneOuverte) return 'constitution';

  return candidature.statut === 'candidat' ? 'en_examen' : 'non_soumis';
}

/** Après la clôture ou la décision, le dossier ne se modifie plus : on le présente en lecture. */
export function isDossierFige(phase: DossierPhase): boolean {
  return phase !== 'constitution';
}
