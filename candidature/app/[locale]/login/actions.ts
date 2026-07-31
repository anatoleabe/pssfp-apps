'use server';

import { loginCandidat } from '@/lib/api/client';
import { setCandidatToken } from '@/lib/auth/session';

export interface LoginResult {
  ok: boolean;
  redirectTo?: string;
  errorKind?: 'invalid' | 'locked' | 'throttled' | 'banned' | 'network';
  message?: string;
  retryAfter?: number;
}

/**
 * Server Action de login candidat.
 *
 * Codes statut backend (cf. PR B AuthCandidatController) :
 * - 200 -> set cookie + redirect /dossier
 * - 401 -> message générique anti-énumération
 * - 423 -> phone lockout (30 min)
 * - 429 -> rate limit IP (3/10min) ou ban (50/24h)
 */
export async function loginAction(phone: string, pin: string): Promise<LoginResult> {
  const response = await loginCandidat({ phone_e164: phone, pin });

  if (response.ok) {
    await setCandidatToken(response.data.token, response.data.expires_at);
    return { ok: true, redirectTo: '/dossier' };
  }

  if (response.status === 401) {
    return { ok: false, errorKind: 'invalid', message: 'Numéro de téléphone ou PIN incorrect.' };
  }
  if (response.status === 423) {
    return {
      ok: false,
      errorKind: 'locked',
      message: 'Compte temporairement bloqué après trop d\'échecs. Réessayez dans 30 minutes.',
    };
  }
  if (response.status === 429) {
    return {
      ok: false,
      errorKind: 'throttled',
      message: 'Trop de tentatives. Réessayez dans quelques minutes.',
    };
  }

  return {
    ok: false,
    errorKind: 'network',
    message: response.message ?? 'Une erreur réseau est survenue. Réessayez.',
  };
}
