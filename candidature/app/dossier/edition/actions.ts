'use server';

import { redirect } from 'next/navigation';
import { revalidatePath } from 'next/cache';
import { putApplicationsMe } from '@/lib/api/client';
import { getCandidatToken } from '@/lib/auth/session';
import type { EditableFields } from '@/lib/dossier/editableFields';

export interface SaveResult {
  ok: boolean;
  status?: 'locked' | 'validation' | 'network';
  errors?: Record<string, string>;
  message?: string;
}

/**
 * PUT /v1/applications/me partial — appelé par DossierEditionForm avec un
 * diff des champs modifiés (cf. spec PR H auto-save 2s).
 *
 * - 401 → redirect /login
 * - 409 → status: 'locked' (dossier déjà soumis)
 * - 422 → mapping field → message FR
 * - 5xx ou réseau → message générique
 */
export async function saveDossierFieldsAction(diff: EditableFields): Promise<SaveResult> {
  const token = await getCandidatToken();
  if (!token) {
    redirect('/login?reason=session_expired');
  }

  if (Object.keys(diff).length === 0) {
    return { ok: true };
  }

  const r = await putApplicationsMe(diff as never, token);

  if (r.ok) {
    // On invalide uniquement /dossier — la page courante /dossier/edition
    // est gérée côté client (state form) et n'a pas besoin de revalidation
    // SSR à chaque autosave (cf. revue PR H : économise des fetch inutiles).
    revalidatePath('/dossier');
    return { ok: true };
  }

  if (r.status === 401) {
    redirect('/login?reason=session_expired');
  }

  if (r.status === 409) {
    return {
      ok: false,
      status: 'locked',
      message:
        'Votre dossier est verrouillé : il a déjà été soumis et ne peut plus être modifié.',
    };
  }

  if (r.status === 422) {
    const errors: Record<string, string> = {};
    if (r.errors) {
      for (const [field, msgs] of Object.entries(r.errors)) {
        if (msgs && msgs[0]) {
          errors[field] = msgs[0];
        }
      }
    }
    return { ok: false, status: 'validation', errors, message: r.message };
  }

  return { ok: false, status: 'network', message: r.message ?? 'Erreur réseau.' };
}
