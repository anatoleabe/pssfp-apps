import { NextResponse } from 'next/server';

import { getCandidatToken } from '@/lib/auth/session';
import { redirectTo } from '@/lib/http/redirect';

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/v1';
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/** GET /dossier/parcours/communiques/{uuid} — communiqué signé diffusé par la scolarité (PDF). */
export async function GET(_request: Request, { params }: { params: { uuid: string } }): Promise<NextResponse> {
  const token = await getCandidatToken();
  if (!token) {
    return redirectTo('/login?reason=session_expired');
  }
  if (!UUID.test(params.uuid)) {
    return redirectTo('/dossier/parcours?erreur=communique');
  }
  try {
    const api = await fetch(`${API_BASE_URL}/applications/me/calendrier/communiques/${params.uuid}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/pdf' },
      cache: 'no-store',
    });
    if (!api.ok || api.body === null) {
      return redirectTo('/dossier/parcours?erreur=communique');
    }
    return new NextResponse(api.body, {
      status: 200,
      headers: {
        'Content-Type': 'application/pdf',
        'Content-Disposition': api.headers.get('Content-Disposition') ?? 'attachment; filename="communique.pdf"',
        'Cache-Control': 'no-store, private',
      },
    });
  } catch {
    return redirectTo('/dossier/parcours?erreur=communique');
  }
}
