import { NextResponse } from 'next/server';

import { getCandidatToken } from '@/lib/auth/session';
import { redirectTo } from '@/lib/http/redirect';

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/v1';

/** GET /dossier/requetes/{id}/document — document délivré en réponse à une demande. */
export async function GET(_request: Request, { params }: { params: { id: string } }): Promise<NextResponse> {
  const token = await getCandidatToken();
  if (!token) {
    return redirectTo('/login?reason=session_expired');
  }
  if (!/^[0-9a-f-]{36}$/.test(params.id)) {
    return redirectTo('/dossier/requetes?erreur=1');
  }
  try {
    const api = await fetch(`${API_BASE_URL}/applications/me/requetes/${params.id}/document`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/pdf' },
      cache: 'no-store',
    });
    if (!api.ok || api.body === null) {
      return redirectTo('/dossier/requetes?erreur=1');
    }
    return new NextResponse(api.body, {
      status: 200,
      headers: {
        'Content-Type': 'application/pdf',
        'Content-Disposition': api.headers.get('Content-Disposition') ?? 'attachment; filename="document.pdf"',
        'Cache-Control': 'no-store, private',
      },
    });
  } catch {
    return redirectTo('/dossier/requetes?erreur=1');
  }
}
