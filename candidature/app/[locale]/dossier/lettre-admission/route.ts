import { NextResponse } from 'next/server';

import { redirectTo } from '@/lib/http/redirect';
import { getCandidatToken } from '@/lib/auth/session';

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/v1';

/**
 * GET /dossier/lettre-admission — proxy authentifié de la lettre signée.
 *
 * Même principe que /dossier/recipisse : le jeton est un cookie httpOnly, donc
 * cette route l'envoie à l'API, puis renvoie le navigateur vers l'URL signée
 * (30 min). Le PDF n'est jamais bufferisé ici.
 */
export async function GET(): Promise<NextResponse> {
  const token = await getCandidatToken();
  if (!token) {
    return redirectTo('/login?reason=session_expired');
  }

  let apiResponse: Response;
  try {
    apiResponse = await fetch(`${API_BASE_URL}/applications/me/lettre-admission`, {
      method: 'GET',
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
      },
      // Ne pas suivre la 302 : on veut récupérer l'en-tête Location (URL signée).
      redirect: 'manual',
      cache: 'no-store',
    });
  } catch {
    return redirectTo('/dossier?lettre_error=unreachable');
  }

  const location = apiResponse.headers.get('location');
  if ((apiResponse.status === 302 || apiResponse.status === 301) && location) {
    return NextResponse.redirect(location);
  }

  if (apiResponse.status === 401) {
    return redirectTo('/login?reason=session_expired');
  }

  return redirectTo(`/dossier?lettre_error=${apiResponse.status === 404 ? 'not_ready' : 'server'}`);
}
