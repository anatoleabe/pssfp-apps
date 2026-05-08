'use client';

import { useEffect, useRef, useState, type KeyboardEvent } from 'react';

export interface PinInputProps {
  value: string;
  onChange: (next: string) => void;
  ariaLabel: string;
  testId?: string;
  /** Désactive l'autofocus initial (utile pour les confirmations en 2e position). */
  noAutoFocus?: boolean;
}

/**
 * 6 cases auto-focus chainées. Backspace remonte au champ précédent.
 * Chaque case ne reçoit qu'un chiffre.
 */
export function PinInput({ value, onChange, ariaLabel, testId, noAutoFocus }: PinInputProps): JSX.Element {
  const refs = useRef<Array<HTMLInputElement | null>>([]);
  const [chars, setChars] = useState<string[]>(() => splitToSix(value));

  useEffect(() => {
    setChars(splitToSix(value));
  }, [value]);

  useEffect(() => {
    if (!noAutoFocus) {
      refs.current[0]?.focus();
    }
  }, [noAutoFocus]);

  const updateAt = (idx: number, char: string): void => {
    const next = [...chars];
    next[idx] = char;
    setChars(next);
    onChange(next.join(''));
  };

  return (
    <div
      role="group"
      aria-label={ariaLabel}
      data-testid={testId}
      className="flex gap-2"
    >
      {chars.map((char, idx) => (
        <input
          key={idx}
          ref={(el) => {
            refs.current[idx] = el;
          }}
          type="text"
          inputMode="numeric"
          autoComplete="one-time-code"
          aria-label={`${ariaLabel} — chiffre ${idx + 1}`}
          maxLength={1}
          value={char}
          onChange={(e) => {
            const v = e.target.value.replace(/\D/g, '').slice(-1);
            updateAt(idx, v);
            if (v && idx < 5) {
              refs.current[idx + 1]?.focus();
            }
          }}
          onKeyDown={(e: KeyboardEvent<HTMLInputElement>) => {
            if (e.key === 'Backspace' && !chars[idx] && idx > 0) {
              refs.current[idx - 1]?.focus();
            }
            if (e.key === 'ArrowLeft' && idx > 0) {
              refs.current[idx - 1]?.focus();
            }
            if (e.key === 'ArrowRight' && idx < 5) {
              refs.current[idx + 1]?.focus();
            }
          }}
          className="h-12 w-10 rounded-md border border-gray-300 text-center text-xl font-medium text-[#333333] focus:border-[#6B2FA0] focus:outline-none focus:ring-2 focus:ring-[#6B2FA0]/30"
        />
      ))}
    </div>
  );
}

function splitToSix(value: string): string[] {
  const digits = (value || '').replace(/\D/g, '').slice(0, 6).split('');
  while (digits.length < 6) {
    digits.push('');
  }
  return digits;
}
