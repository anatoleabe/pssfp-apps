'use server';

import { revalidatePath } from 'next/cache';
import { redirect } from 'next/navigation';
import {
  deleteCandidatureDocument,
  uploadCandidatureDocument,
  type CandidatureDocumentItem,
  type CandidatureDocumentType,
} from '@/lib/api/client';
import { getCandidatToken } from '@/lib/auth/session';

export interface UploadDocumentActionResult {
  ok: boolean;
  document?: CandidatureDocumentItem;
  message: string;
}

export async function uploadDocumentAction(formData: FormData): Promise<UploadDocumentActionResult> {
  const token = await getCandidatToken();
  if (!token) {
    redirect('/login?reason=session_expired');
  }

  const fichier = formData.get('fichier');
  const type = formData.get('type');
  if (!(fichier instanceof File) || fichier.size === 0) {
    return { ok: false, message: 'Aucun fichier sélectionné.' };
  }
  if (typeof type !== 'string' || type === '') {
    return { ok: false, message: 'Type de pièce manquant.' };
  }

  const r = await uploadCandidatureDocument(fichier, type as CandidatureDocumentType, token);
  if (!r.ok) {
    if (r.status === 401) {
      redirect('/login?reason=session_expired');
    }
    if (r.status === 409) {
      return { ok: false, message: 'Le dossier est verrouillé : les pièces ne peuvent plus être modifiées.' };
    }
    if (r.status === 422 && r.errors?.fichier?.[0]) {
      return { ok: false, message: r.errors.fichier[0] };
    }
    return { ok: false, message: r.message ?? 'Erreur lors de l\'envoi.' };
  }

  revalidatePath('/dossier/pieces');
  revalidatePath('/dossier');
  return { ok: true, document: r.data, message: 'Pièce enregistrée.' };
}

export interface DeleteDocumentActionResult {
  ok: boolean;
  message: string;
}

export async function deleteDocumentAction(uuid: string): Promise<DeleteDocumentActionResult> {
  const token = await getCandidatToken();
  if (!token) {
    redirect('/login?reason=session_expired');
  }

  const r = await deleteCandidatureDocument(uuid, token);
  if (!r.ok) {
    if (r.status === 401) {
      redirect('/login?reason=session_expired');
    }
    if (r.status === 409) {
      return { ok: false, message: 'Le dossier est verrouillé : les pièces ne peuvent plus être supprimées.' };
    }
    return { ok: false, message: r.message ?? 'Erreur lors de la suppression.' };
  }

  revalidatePath('/dossier/pieces');
  revalidatePath('/dossier');
  return { ok: true, message: 'Pièce supprimée.' };
}
