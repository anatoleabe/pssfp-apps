/**
 * Helpers de formatage et validation phone E.164.
 *
 * V1 simple — sans dépendance libphonenumber-js (overkill pour le V1).
 * Le format strict E.164 est `+[1-9]\d{6,14}`. Pour le Cameroun (+237),
 * les numéros locaux sont à 9 chiffres après l'indicatif (6XXXXXXXX ou
 * 2XXXXXXXX), totalisant 12 chiffres avec le préfixe (cf. P-min-1 PR E).
 */

export const E164_REGEX = /^\+[1-9]\d{6,14}$/;

/** Concatène un indicatif (`+237`) et un numéro local (`691234567`) en E.164. */
export function buildE164(indicatif: string, localDigits: string): string {
  const cleanIndic = indicatif.replace(/[^\d+]/g, '');
  const cleanLocal = localDigits.replace(/\D/g, '');
  if (!cleanIndic) {
    return cleanLocal;
  }
  const prefix = cleanIndic.startsWith('+') ? cleanIndic : `+${cleanIndic}`;
  return `${prefix}${cleanLocal}`;
}

export function isValidE164(value: string): boolean {
  return E164_REGEX.test(value);
}

/**
 * Validation supplémentaire pour le Cameroun (+237) : le numéro local
 * doit faire 9 chiffres et commencer par 2 ou 6 (mobiles MTN/Orange/Camtel).
 */
export function isValidCameroonNumber(phoneE164: string): boolean {
  if (!phoneE164.startsWith('+237')) {
    return false;
  }
  const local = phoneE164.slice(4);
  return /^[26]\d{8}$/.test(local);
}
