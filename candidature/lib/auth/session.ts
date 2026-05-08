'use server';

import { cookies } from 'next/headers';

const COOKIE_NAME = 'pssfp_candidat_token';
const COOKIE_EXPIRES_AT = 'pssfp_candidat_expires';

/**
 * Persiste le token Sanctum candidat dans un cookie httpOnly Secure SameSite=Lax.
 *
 * Path=/ pour partager entre toutes les pages candidature.
 * SameSite=Lax (pas Strict) — permet la navigation depuis un email accept/refuse.
 * Domain=.pssfp.net en prod (cross-app possible), absent en dev (cookie localhost).
 * maxAge dérivé de `expiresAt` retourné par /v1/auth/candidat/register|login.
 */
export async function setCandidatToken(token: string, expiresAt: string | null): Promise<void> {
  const store = await cookies();
  const isProd = process.env.NODE_ENV === 'production';

  let maxAgeSeconds: number;
  if (expiresAt) {
    const expiresMs = new Date(expiresAt).getTime() - Date.now();
    maxAgeSeconds = Math.max(60, Math.floor(expiresMs / 1000));
  } else {
    // 7 jours par défaut (TTL token candidat ADR-0005 dérogation candidat).
    maxAgeSeconds = 7 * 24 * 60 * 60;
  }

  const baseOptions = {
    httpOnly: true,
    secure: isProd,
    sameSite: 'lax' as const,
    path: '/',
    maxAge: maxAgeSeconds,
    ...(isProd ? { domain: '.pssfp.net' } : {}),
  };

  store.set(COOKIE_NAME, token, baseOptions);
  // Le cookie expires_at est lu côté client pour afficher un compteur ;
  // pas httpOnly volontairement (lisible JS pour permettre un toast « session expire »).
  if (expiresAt) {
    store.set(COOKIE_EXPIRES_AT, expiresAt, { ...baseOptions, httpOnly: false });
  }
}

export async function getCandidatToken(): Promise<string | null> {
  const store = await cookies();
  return store.get(COOKIE_NAME)?.value ?? null;
}

export async function clearCandidatToken(): Promise<void> {
  const store = await cookies();
  store.delete(COOKIE_NAME);
  store.delete(COOKIE_EXPIRES_AT);
}
