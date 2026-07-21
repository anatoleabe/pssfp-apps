'use client';

import { useEffect, useState } from 'react';
import type { Pays } from '@/lib/api/types';
import { buildE164, isValidE164 } from '@/lib/format/phone';

export interface PhoneInputValue {
  indicatif: string;
  local: string;
  e164: string;
  countryCode: string;
}

export interface PhoneInputProps {
  pays: Pays[];
  value: PhoneInputValue;
  onChange: (next: PhoneInputValue) => void;
  testIdPrefix?: string;
  ariaInvalid?: boolean;
  ariaDescribedBy?: string;
  onBlur?: () => void;
  numberTestId?: string;
}

/**
 * Champ téléphone composite : select indicatif (par pays) + numéro local digits-only.
 * Recompose automatiquement la valeur E.164 à chaque changement.
 */
export function PhoneInput({
  pays,
  value,
  onChange,
  testIdPrefix = 'phone',
  ariaInvalid,
  ariaDescribedBy,
  onBlur,
  numberTestId,
}: PhoneInputProps): JSX.Element {
  const [local, setLocal] = useState(value.local ?? '');

  useEffect(() => {
    setLocal(value.local ?? '');
  }, [value.local]);

  const update = (next: Partial<PhoneInputValue>): void => {
    const merged: PhoneInputValue = {
      countryCode: next.countryCode ?? value.countryCode,
      indicatif: next.indicatif ?? value.indicatif,
      local: next.local ?? local,
      e164: '',
    };
    merged.e164 = buildE164(merged.indicatif, merged.local);
    onChange(merged);
  };

  return (
    <div className="flex gap-2">
      <select
        data-testid={`${testIdPrefix}-country`}
        aria-label="Indicatif pays"
        value={value.countryCode}
        onChange={(e) => {
          const code = e.target.value;
          const found = pays.find((p) => p.code_iso === code);
          update({ countryCode: code, indicatif: found?.indicatif ?? '+' });
        }}
        className="h-11 rounded-md border border-gray-300 px-2 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
      >
        {pays.map((p) => (
          <option key={p.code_iso} value={p.code_iso}>
            {p.code_iso} {p.indicatif}
          </option>
        ))}
      </select>
      <input
        data-testid={numberTestId ?? `${testIdPrefix}-number`}
        type="tel"
        inputMode="numeric"
        autoComplete="tel-national"
        aria-label="Numéro de téléphone"
        aria-invalid={ariaInvalid && !isValidE164(value.e164)}
        aria-describedby={ariaDescribedBy}
        placeholder="691234567"
        value={local}
        onChange={(e) => {
          const rawDigits = e.target.value.replace(/\D/g, '');
          const countryDigits = value.indicatif.replace(/\D/g, '');
          // Accepte aussi un collage international complet dans le champ local.
          const digits = rawDigits.startsWith(countryDigits)
            ? rawDigits.slice(countryDigits.length)
            : rawDigits;
          setLocal(digits);
          update({ local: digits });
        }}
        onBlur={onBlur}
        className="h-11 flex-1 rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
      />
    </div>
  );
}
