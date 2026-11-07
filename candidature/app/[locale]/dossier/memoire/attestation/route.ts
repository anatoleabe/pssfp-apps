import { NextResponse } from 'next/server';

import { getCandidatToken } from '@/lib/auth/session';
import { redirectTo } from '@/lib/http/redirect';

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/v1';

/** GET /dossier/memoire/attestation — attestation d'autorisation de dépôt du mémoire (PDF). */
export async function GET(): Promise<NextResponse> {
  const token = await getCandidatToken();
  if (!token) {
    return redirectTo('/login?reason=session_expired');
  }
  try {
    const api = await fetch(`${API_BASE_URL}/applications/me/memoire/attestation`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/pdf' },
      cache: 'no-store',
    });
    if (!api.ok || api.body === null) {
      return redirectTo('/dossier/memoire?erreur=1');
    }
    return new NextResponse(api.body, {
      status: 200,
      headers: {
        'Content-Type': 'application/pdf',
        'Content-Disposition': api.headers.get('Content-Disposition') ?? 'attachment; filename="attestation.pdf"',
        'Cache-Control': 'no-store, private',
      },
    });
  } catch {
    return redirectTo('/dossier/memoire?erreur=1');
  }
}
