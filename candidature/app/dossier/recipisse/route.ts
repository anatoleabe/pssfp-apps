import { NextResponse } from 'next/server';
import { getCandidatToken } from '@/lib/auth/session';

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/v1';

/**
 * GET /dossier/recipisse — proxy authentifié de téléchargement du récépissé.
 *
 * Le bouton « Récépissé PDF » ne peut PAS pointer directement vers l'API :
 * le token candidat est un cookie httpOnly (domaine apply.pssfp.org) que le
 * navigateur n'envoie jamais en en-tête Bearer vers api.pssfp.org. Ouvrir
 * l'URL API directement aboutissait donc à une requête non authentifiée →
 * 500 côté Laravel (redirection vers une route `login` inexistante en mode API).
 *
 * Cette route serveur lit le cookie, appelle l'API authentifiée en Bearer,
 * récupère la redirection 302 vers l'URL signée MinIO (30 min), et renvoie
 * le navigateur vers cette URL signée. Le PDF n'est jamais bufferisé ici.
 */
export async function GET(request: Request): Promise<NextResponse> {
  const token = await getCandidatToken();
  if (!token) {
    return NextResponse.redirect(new URL('/login?reason=session_expired', request.url));
  }

  let apiResponse: Response;
  try {
    apiResponse = await fetch(`${API_BASE_URL}/applications/me/recipisse`, {
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
    return NextResponse.redirect(new URL('/dossier?recipisse_error=unreachable', request.url));
  }

  // L'API répond par une redirection (302/301) vers l'URL signée MinIO.
  const location = apiResponse.headers.get('location');
  if ((apiResponse.status === 302 || apiResponse.status === 301) && location) {
    return NextResponse.redirect(location);
  }

  if (apiResponse.status === 401) {
    return NextResponse.redirect(new URL('/login?reason=session_expired', request.url));
  }

  // 404 : récépissé pas encore généré (candidature non soumise) ; autre : erreur.
  const kind = apiResponse.status === 404 ? 'not_ready' : 'server';
  return NextResponse.redirect(new URL(`/dossier?recipisse_error=${kind}`, request.url));
}
