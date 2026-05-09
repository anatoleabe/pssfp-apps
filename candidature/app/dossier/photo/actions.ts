'use server';

import { revalidatePath } from 'next/cache';
import { redirect } from 'next/navigation';
import { deleteMyPhoto, uploadPhoto } from '@/lib/api/client';
import { getCandidatToken } from '@/lib/auth/session';

export interface UploadActionResult {
  ok: boolean;
  signedUrl: string;
  message: string;
}

export async function uploadPhotoAction(formData: FormData): Promise<UploadActionResult> {
  const token = await getCandidatToken();
  if (!token) {
    redirect('/login?reason=session_expired');
  }

  const photo = formData.get('photo');
  if (!(photo instanceof File) || photo.size === 0) {
    return { ok: false, signedUrl: '', message: 'Aucun fichier sélectionné.' };
  }

  const r = await uploadPhoto(photo, token);
  if (!r.ok) {
    if (r.status === 401) {
      redirect('/login?reason=session_expired');
    }
    if (r.status === 409) {
      return {
        ok: false,
        signedUrl: '',
        message: 'Le dossier est verrouillé : la photo ne peut plus être modifiée.',
      };
    }
    if (r.status === 422 && r.errors?.photo?.[0]) {
      return { ok: false, signedUrl: '', message: r.errors.photo[0] };
    }
    return { ok: false, signedUrl: '', message: r.message ?? 'Erreur lors de l\'envoi.' };
  }

  revalidatePath('/dossier/photo');
  revalidatePath('/dossier');
  return { ok: true, signedUrl: r.data.photo_url, message: 'Photo enregistrée.' };
}

export interface DeleteActionResult {
  ok: boolean;
  message: string;
}

export async function deletePhotoAction(): Promise<DeleteActionResult> {
  const token = await getCandidatToken();
  if (!token) {
    redirect('/login?reason=session_expired');
  }

  const r = await deleteMyPhoto(token);
  if (!r.ok) {
    if (r.status === 401) {
      redirect('/login?reason=session_expired');
    }
    if (r.status === 409) {
      return {
        ok: false,
        message: 'Le dossier est verrouillé : la photo ne peut plus être supprimée.',
      };
    }
    return { ok: false, message: r.message ?? 'Erreur lors de la suppression.' };
  }

  revalidatePath('/dossier/photo');
  revalidatePath('/dossier');
  return { ok: true, message: 'Photo supprimée.' };
}
