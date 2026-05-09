'use server';

import { redirect } from 'next/navigation';
import { revalidatePath } from 'next/cache';
import {
  logoutCandidat,
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

export async function submitDossierAction(): Promise<DossierActionResult> {
  const token = await getCandidatToken();
  if (!token) {
    return { ok: false, errorKind: 'unauthenticated' };
  }

  const r = await submitMyCandidature(token);
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
    await logoutCandidat(token);
  }
  await clearCandidatToken();
  redirect('/login?reason=logged_out');
}
