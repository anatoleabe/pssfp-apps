import { redirect } from '@/navigation';
import { getMyCandidature, getMyScolarite, type MaScolarite, type MyCandidature, type ScolaritePalier } from '@/lib/api/client';
import { getCandidatToken, isConsultation } from '@/lib/auth/session';

export interface EspaceAuditeur {
  candidature: MyCandidature;
  scolarite: MaScolarite;
  consultation: boolean;
}

/**
 * Données d'une rubrique de l'espace auditeur. Sans session : connexion. Compte
 * qui n'est pas auditeur : retour à la page du dossier, qui lui reste propre.
 */
export async function chargerEspaceAuditeur(): Promise<EspaceAuditeur> {
  const token = await getCandidatToken();
  if (!token) {
    redirect('/login?reason=session_expired');
  }

  const [candidature, scolarite] = await Promise.all([getMyCandidature(token), getMyScolarite(token)]);
  if ((!candidature.ok && candidature.status === 401) || (!scolarite.ok && scolarite.status === 401)) {
    redirect('/auth/session-expired');
  }
  if (!candidature.ok || !scolarite.ok) {
    redirect('/dossier');
  }

  return { candidature: candidature.data, scolarite: scolarite.data, consultation: await isConsultation() };
}

/** Premier palier non soldé, ou null si tout est réglé. */
export function prochainPalier(scolarite: MaScolarite): ScolaritePalier | null {
  return scolarite.paliers.find((p) => !p.solde) ?? null;
}
