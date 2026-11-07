import { NextResponse, type NextRequest } from 'next/server';

import { getCandidatToken } from '@/lib/auth/session';
import { redirectTo } from '@/lib/http/redirect';

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/v1';
const TYPES = new Set(['releve_notes', 'attestation_reussite', 'certificat_scolarite']);

/**
 * GET /dossier/documents-officiels/{type} — relevé de notes, attestation de réussite ou
 * certificat de scolarité de l'auditeur connecté. Le jeton (cookie httpOnly) est relayé à
 * l'API ; le PDF est rendu en flux, sans cache.
 */
export async function GET(request: NextRequest, { params }: { params: { type: string } }): Promise<NextResponse> {
  const token = await getCandidatToken();
  if (!token) {
    return redirectTo('/login?reason=session_expired');
  }
  if (!TYPES.has(params.type)) {
    return redirectTo('/dossier/resultats?document_error=1');
  }
  const deliberation = request.nextUrl.searchParams.get('deliberation') ?? '';
  const query = /^[0-9a-f-]{36}$/.test(deliberation) ? `?deliberation=${deliberation}` : '';

  let apiResponse: Response;
  try {
    apiResponse = await fetch(`${API_BASE_URL}/applications/me/documents-officiels/${params.type}${query}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/pdf' },
      cache: 'no-store',
    });
  } catch {
    return redirectTo('/dossier/resultats?document_error=1');
  }
  if (apiResponse.status === 401) {
    return redirectTo('/login?reason=session_expired');
  }
  if (!apiResponse.ok || apiResponse.body === null) {
    return redirectTo('/dossier/resultats?document_error=1');
  }

  return new NextResponse(apiResponse.body, {
    status: 200,
    headers: {
      'Content-Type': 'application/pdf',
      'Content-Disposition': apiResponse.headers.get('Content-Disposition') ?? `attachment; filename="${params.type}.pdf"`,
      'Cache-Control': 'no-store, private',
    },
  });
}
