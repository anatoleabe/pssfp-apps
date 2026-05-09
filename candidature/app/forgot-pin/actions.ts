'use server';

import { redirect } from 'next/navigation';
import { forgotPin, resetPin, verifyOtp } from '@/lib/api/client';
import {
  clearPinResetToken,
  getPinResetToken,
  setPinResetToken,
} from '@/lib/auth/session';

export interface SimpleResult {
  ok: boolean;
  errorKind?: 'invalid' | 'expired' | 'already_used' | 'throttled' | 'network';
  message?: string;
}

/**
 * Étape 1 — toujours 202, anti-énumération.
 */
export async function requestOtpAction(phone: string): Promise<SimpleResult> {
  const r = await forgotPin({ phone_e164: phone });
  if (r.ok) {
    return { ok: true };
  }
  // Backend retourne 202 dans tous les cas. Si on tombe ici, c'est un souci réseau.
  return { ok: false, errorKind: 'network', message: r.message ?? 'Erreur réseau.' };
}

/**
 * Étape 2 — verify OTP. Set le cookie pin_reset_token sur succès.
 */
export async function verifyOtpAction(phone: string, code: string): Promise<SimpleResult> {
  const r = await verifyOtp({ phone_e164: phone, code });
  if (r.ok) {
    await setPinResetToken(r.data.pin_reset_token, r.data.expires_at);
    return { ok: true };
  }

  if (r.status === 401) {
    return { ok: false, errorKind: 'invalid', message: 'Code incorrect ou expiré.' };
  }
  if (r.status === 410) {
    return { ok: false, errorKind: 'expired', message: 'Le code a expiré. Demandez-en un nouveau.' };
  }
  if (r.status === 409) {
    return { ok: false, errorKind: 'already_used', message: 'Ce code a déjà été utilisé.' };
  }
  if (r.status === 429) {
    return { ok: false, errorKind: 'throttled', message: 'Trop de tentatives. Réessayez dans une heure.' };
  }
  return { ok: false, errorKind: 'network', message: r.message ?? 'Erreur réseau.' };
}

/**
 * Étape 3 — reset PIN. Lit le cookie pin_reset_token, appelle backend,
 * efface le cookie. Le candidat doit ensuite se reconnecter manuellement.
 */
export async function resetPinAction(pin: string, pinConfirmation: string): Promise<SimpleResult> {
  const token = await getPinResetToken();
  if (!token) {
    return { ok: false, errorKind: 'expired', message: 'Session de réinitialisation expirée. Recommencez.' };
  }

  const r = await resetPin({ pin, pin_confirmation: pinConfirmation }, token);
  if (r.ok) {
    await clearPinResetToken();
    redirect('/login?reason=pin_reset');
  }

  if (r.status === 422) {
    return { ok: false, errorKind: 'invalid', message: 'PIN refusé : trop courant, identique au numéro de téléphone, ou à votre date de naissance.' };
  }
  return { ok: false, errorKind: 'network', message: r.message ?? 'Erreur lors du reset.' };
}
