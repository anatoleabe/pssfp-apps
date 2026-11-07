import { type NextRequest } from 'next/server';

import { getCandidatToken, isConsultation } from '@/lib/auth/session';
import { redirectTo } from '@/lib/http/redirect';

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/v1';

/** POST /dossier/requetes/{id}/annuler — annulation par l'auditeur, avant la vérification. */
export async function POST(_request: NextRequest, { params }: { params: { id: string } }) {
  const token = await getCandidatToken();
  if (!token) {
    return redirectTo('/login?reason=session_expired');
  }
  if ((await isConsultation()) || !/^[0-9a-f-]{36}$/.test(params.id)) {
    return redirectTo('/dossier/requetes');
  }
  try {
    const api = await fetch(`${API_BASE_URL}/applications/me/requetes/${params.id}/annuler`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
      cache: 'no-store',
    });
    return redirectTo(api.ok ? '/dossier/requetes?annulee=1' : '/dossier/requetes?erreur=1');
  } catch {
    return redirectTo('/dossier/requetes?erreur=1');
  }
}
