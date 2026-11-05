import { NextResponse } from 'next/server';

import { getCandidatToken } from '@/lib/auth/session';
import { redirectTo } from '@/lib/http/redirect';

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/v1';

/**
 * GET /dossier/releve — relevé de situation de l'auditeur connecté.
 *
 * Le jeton est un cookie httpOnly : cette route l'envoie à l'API et renvoie le
 * PDF en flux, sans le garder ni le mettre en cache.
 */
export async function GET(): Promise<NextResponse> {
  const token = await getCandidatToken();
  if (!token) {
    return redirectTo('/login?reason=session_expired');
  }

  let apiResponse: Response;
  try {
    apiResponse = await fetch(`${API_BASE_URL}/applications/me/releve-scolarite`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/pdf' },
      cache: 'no-store',
    });
  } catch {
    return redirectTo('/dossier?releve_error=1');
  }

  if (apiResponse.status === 401) {
    return redirectTo('/login?reason=session_expired');
  }
  if (!apiResponse.ok || apiResponse.body === null) {
    return redirectTo('/dossier?releve_error=1');
  }

  return new NextResponse(apiResponse.body, {
    status: 200,
    headers: {
      'Content-Type': 'application/pdf',
      'Content-Disposition': 'attachment; filename="releve-scolarite.pdf"',
      'Cache-Control': 'no-store, private',
    },
  });
}
