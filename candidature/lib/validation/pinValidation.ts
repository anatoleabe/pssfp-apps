/**
 * Validation PIN candidat (cf. ADR-0007).
 *
 * Pur TypeScript, partagé entre :
 * - WizardStep4Engagement côté client (validation immédiate avant submit)
 * - Server Action submitInscription (défense en profondeur)
 *
 * Doit reproduire les règles de backend/app/Services/PinService.php :
 * - format \d{6}
 * - blacklist (~50 PIN courants — sous-ensemble synchronisé)
 * - pas les 6 derniers chiffres du téléphone
 * - pas la date de naissance DDMMYY ni YYMMDD
 */

const PIN_BLACKLIST: ReadonlyArray<string> = [
  '123456', '654321', '012345', '543210',
  '111111', '000000', '222222', '333333', '444444',
  '555555', '666666', '777777', '888888', '999999',
  '123123', '121212', '123321', '132132', '112233',
  '101010', '010101', '202020',
  '159753', '159357', '147258', '258369',
  '789456', '147147', '321321', '456789',
  '202120', '202220', '202320', '202420', '202520', '202620', '202720',
  '010100', '010199', '010180', '010190',
  '311299',
  '777111', '111777', '555111', '999000',
];

export type PinValidationCode =
  | 'pin.invalid_format'
  | 'pin.blacklisted'
  | 'pin.matches_phone_suffix'
  | 'pin.matches_date_of_birth';

export interface PinValidationResult {
  ok: boolean;
  reasons: PinValidationCode[];
}

export function validateCandidatePin(
  pin: string,
  phoneE164: string,
  dateNaissanceISO: string | null,
): PinValidationResult {
  const reasons: PinValidationCode[] = [];

  if (!/^\d{6}$/.test(pin)) {
    reasons.push('pin.invalid_format');
    return { ok: false, reasons };
  }

  if (PIN_BLACKLIST.includes(pin)) {
    reasons.push('pin.blacklisted');
  }

  if (matchesPhoneSuffix(pin, phoneE164)) {
    reasons.push('pin.matches_phone_suffix');
  }

  if (dateNaissanceISO && matchesDateOfBirth(pin, dateNaissanceISO)) {
    reasons.push('pin.matches_date_of_birth');
  }

  return { ok: reasons.length === 0, reasons };
}

function matchesPhoneSuffix(pin: string, phoneE164: string): boolean {
  const digitsOnly = phoneE164.replace(/\D/g, '');
  if (digitsOnly.length < 6) {
    return false;
  }
  return digitsOnly.slice(-6) === pin;
}

function matchesDateOfBirth(pin: string, dateISO: string): boolean {
  const date = new Date(dateISO);
  if (Number.isNaN(date.getTime())) {
    return false;
  }
  const dd = String(date.getUTCDate()).padStart(2, '0');
  const mm = String(date.getUTCMonth() + 1).padStart(2, '0');
  const yyFull = String(date.getUTCFullYear());
  const yy = yyFull.slice(-2);

  return pin === `${dd}${mm}${yy}` || pin === `${yy}${mm}${dd}`;
}
