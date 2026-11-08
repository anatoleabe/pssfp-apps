import { NextResponse } from 'next/server';

import { getCandidatToken } from '@/lib/auth/session';
import { redirectTo } from '@/lib/http/redirect';

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/v1';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/**
 * GET /dossier/recus/{uuid} — reçu d'un versement de l'auditeur connecté (PDF).
 *
 * Le jeton est un cookie httpOnly : cette route l'envoie à l'API, qui vérifie que
 * le reçu appartient bien à l'auditeur, et renvoie le PDF en flux, sans cache.
 */
export async function GET(_request: Request, { params }: { params: { uuid: string } }): Promise<NextResponse> {
  const { uuid } = params;
  if (!UUID.test(uuid)) {
    return redirectTo('/dossier?recu_error=1');
  }
  const token = await getCandidatToken();
  if (!token) {
    return redirectTo('/login?reason=session_expired');
  }

  let apiResponse: Response;
  try {
    apiResponse = await fetch(`${API_BASE_URL}/applications/me/scolarite/recus/${uuid}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/pdf' },
      cache: 'no-store',
    });
  } catch {
    return redirectTo('/dossier?recu_error=1');
  }

  if (apiResponse.status === 401) {
    return redirectTo('/login?reason=session_expired');
  }
  if (!apiResponse.ok || apiResponse.body === null) {
    return redirectTo('/dossier?recu_error=1');
  }

  // En-tête reconstruit ici : seul un nom de reçu au format attendu est repris de l'API.
  const nom = /filename="(rcpt-\d{4}-\d{6})\.pdf"/.exec(apiResponse.headers.get('Content-Disposition') ?? '')?.[1] ?? 'recu';

  return new NextResponse(apiResponse.body, {
    status: 200,
    headers: {
      'Content-Type': 'application/pdf',
      'Content-Disposition': `attachment; filename="${nom}.pdf"`,
      'Cache-Control': 'no-store, private',
      'X-Content-Type-Options': 'nosniff',
    },
  });
}
