import { NextRequest, NextResponse } from 'next/server';

import { redirectTo } from '@/lib/http/redirect';

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/v1';
const CODE_PATTERN = /^[A-Za-z0-9]{64}$/;

interface ConsultationSession {
  token: string;
  expires_at: string;
}

/**
 * Ouverture de l'espace d'un candidat par un agent du PSSFP (« Voir son
 * espace » dans l'administration).
 *
 * L'administration redirige ici avec un code à usage unique valable une
 * minute. Il est échangé contre un jeton du candidat limité à la lecture, posé
 * dans les mêmes cookies qu'une session normale, plus un marqueur qui fait
 * afficher le bandeau « Mode consultation ». Route Handler : seul contexte
 * autorisé à écrire des cookies avant la redirection.
 */
export async function GET(request: NextRequest): Promise<NextResponse> {
  const code = request.nextUrl.searchParams.get('code') ?? '';
  const session = CODE_PATTERN.test(code) ? await echanger(code) : null;

  if (session === null) {
    return withHeaders(redirectTo('/login?reason=consultation_expired'));
  }

  const response = redirectTo('/dossier');
  const isProd = process.env.NODE_ENV === 'production';
  const maxAge = Math.max(60, Math.floor((new Date(session.expires_at).getTime() - Date.now()) / 1000));
  const base = {
    secure: isProd,
    sameSite: 'lax' as const,
    path: '/',
    maxAge,
    ...(isProd ? { domain: '.pssfp.org' } : {}),
  };
  response.cookies.set('pssfp_candidat_token', session.token, { ...base, httpOnly: true });
  response.cookies.set('pssfp_candidat_expires', session.expires_at, { ...base, httpOnly: false });
  response.cookies.set('pssfp_candidat_consultation', '1', { ...base, httpOnly: true });

  return withHeaders(response);
}

async function echanger(code: string): Promise<ConsultationSession | null> {
  try {
    const response = await fetch(`${API_BASE_URL}/auth/candidat/consultation`, {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({ code }),
      cache: 'no-store',
      signal: AbortSignal.timeout(5_000),
    });
    if (!response.ok) return null;
    const data = (await response.json()) as Partial<ConsultationSession>;

    return typeof data.token === 'string' && typeof data.expires_at === 'string'
      ? { token: data.token, expires_at: data.expires_at }
      : null;
  } catch {
    return null;
  }
}

/** Le code d'ouverture figure dans l'URL : ni cache, ni transmission en Referer. */
function withHeaders(response: NextResponse): NextResponse {
  response.headers.set('Cache-Control', 'no-store');
  response.headers.set('Referrer-Policy', 'no-referrer');
  return response;
}
