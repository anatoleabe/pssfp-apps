import { NextResponse } from 'next/server';

import { apiPost } from '@/lib/api/client';
import { visiteurHeaders } from '@/lib/api/visiteur';
import { getCandidatToken } from '@/lib/auth/session';
import { DIGEST_MAX, MESSAGE_MAX, PAGE_MAX } from '@/lib/erreurs/signalement';

const CORPS_MAX = 4_000;
const NAVIGATEUR_MAX = 300;
const DELAI_API_MS = 3_000;

/**
 * Relais des écrans d'erreur vers l'API (page « Erreurs techniques » du
 * back-office). Ajoute le compte connecté et l'adresse réelle du visiteur
 * (secret du portail). Répond 204 que l'API soit joignable ou non : le
 * visiteur voit déjà un écran d'erreur, le relais ne doit rien y ajouter.
 */
export async function POST(request: Request): Promise<NextResponse> {
  const texte = await request.text();
  if (texte.length > CORPS_MAX) {
    return new NextResponse(null, { status: 413 });
  }

  let corps: unknown;
  try {
    corps = JSON.parse(texte);
  } catch {
    return new NextResponse(null, { status: 400 });
  }
  const { page, message, digest } = (corps ?? {}) as Record<string, unknown>;
  if (typeof page !== 'string' || typeof message !== 'string' || page === '' || message === '') {
    return new NextResponse(null, { status: 400 });
  }

  const navigateur = (request.headers.get('user-agent') ?? '').slice(0, NAVIGATEUR_MAX);
  await apiPost(
    '/erreurs/portail',
    {
      page: page.slice(0, PAGE_MAX),
      message: message.slice(0, MESSAGE_MAX),
      digest: typeof digest === 'string' ? digest.slice(0, DIGEST_MAX) : null,
      navigateur: navigateur === '' ? null : navigateur,
    },
    { token: await getCandidatToken(), headers: await visiteurHeaders(), timeoutMs: DELAI_API_MS },
  );

  return new NextResponse(null, { status: 204 });
}
