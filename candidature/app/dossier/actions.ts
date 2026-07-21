'use server';

import { redirect } from 'next/navigation';
import { revalidatePath } from 'next/cache';
import {
  logoutCandidat,
  putApplicationsMe,
  submitMyCandidature,
  withdrawMyCandidature,
} from '@/lib/api/client';
import { clearCandidatToken, getCandidatToken } from '@/lib/auth/session';

export interface DossierActionResult {
  ok: boolean;
  redirectTo?: string;
  recipisseUrl?: string;
  errorKind?: 'unauthenticated' | 'incomplete' | 'already_submitted' | 'already_decided' | 'already_withdrawn' | 'network';
  message?: string;
}

/**
 * Crée le dossier candidat s'il n'existe pas encore pour la campagne courante
 * (compte déjà inscrit mais dossier jamais initialisé, ex. tentative pendant
 * une fenêtre où aucune campagne n'était ouverte). PUT vide → upsertForUser
 * côté backend crée la Candidature à partir des infos déjà connues du compte
 * (téléphone, nom, date de naissance).
 *
 * Signature `Promise<void>` imposée par l'usage en `<form action={...}>`
 * direct (server action) — le résultat se lit via redirect + query param.
 */
export async function initDossierAction(): Promise<void> {
  const token = await getCandidatToken();
  if (!token) {
    redirect('/login?reason=session_expired');
  }

  const r = await putApplicationsMe({}, token);
  if (r.ok) {
    revalidatePath('/dossier');
    redirect('/dossier');
  }

  redirect('/dossier?init_error=1');
}

export async function submitDossierAction(
  idempotencyKey?: string,
): Promise<DossierActionResult> {
  const token = await getCandidatToken();
  if (!token) {
    return { ok: false, errorKind: 'unauthenticated' };
  }

  const r = await submitMyCandidature(token, idempotencyKey);
  if (r.ok) {
    revalidatePath('/dossier');
    return { ok: true, recipisseUrl: r.data.recipisse_url };
  }

  if (r.status === 422) {
    return {
      ok: false,
      errorKind: 'incomplete',
      message: 'Profil incomplet — veuillez vérifier les champs manquants ci-dessous.',
    };
  }
  if (r.status === 409) {
    return { ok: false, errorKind: 'already_submitted', message: 'Candidature déjà soumise.' };
  }
  return {
    ok: false,
    errorKind: 'network',
    message: r.message ?? 'Erreur réseau.',
  };
}

export async function withdrawDossierAction(): Promise<DossierActionResult> {
  const token = await getCandidatToken();
  if (!token) {
    return { ok: false, errorKind: 'unauthenticated' };
  }

  const r = await withdrawMyCandidature(token);
  if (r.ok) {
    revalidatePath('/dossier');
    revalidatePath('/dossier/suivi');
    return { ok: true };
  }

  if (r.status === 409) {
    const kind = (r as { errors?: Record<string, string[]> } & { message?: string }).message?.includes('déjà décidée')
      ? 'already_decided'
      : 'already_withdrawn';
    return { ok: false, errorKind: kind, message: r.message };
  }
  return { ok: false, errorKind: 'network', message: r.message ?? 'Erreur réseau.' };
}

export async function logoutAction(): Promise<void> {
  const token = await getCandidatToken();
  if (token) {
    // L'effacement local doit toujours avoir lieu, même si l'API est en panne.
    // L'appel serveur révoque le token Sanctum lorsqu'elle est disponible.
    try {
      await logoutCandidat(token);
    } catch {
      // api client normalise déjà les erreurs ; garde-fou défensif.
    }
  }
  await clearCandidatToken();
  redirect('/login?reason=logged_out');
}
