'use client';

import { useEffect, useRef, useState, type KeyboardEvent } from 'react';
import { cn } from '@/lib/cn';

export interface PinInputProps {
  value: string;
  onChange: (next: string) => void;
  ariaLabel: string;
  testId?: string;
  /** Désactive l'autofocus initial (utile pour les confirmations en 2e position). */
  noAutoFocus?: boolean;
}

/**
 * 6 cases auto-focus chainées — refonte UX.
 *
 * - Cases plus grandes (h-14 w-12)
 * - Animation subtle scale au focus + ring violet
 * - Border emerald quand toutes remplies
 * - Backspace remonte au champ précédent
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

  const allFilled = chars.every((c) => c !== '');

  return (
    <div
      role="group"
      aria-label={ariaLabel}
      data-testid={testId}
      className="inline-flex gap-2"
    >
      {chars.map((char, idx) => {
        const isFilled = char !== '';
        return (
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
            className={cn(
              'h-14 w-12 rounded-pssfp-button border-2 text-center font-heading text-2xl font-bold tabular-nums transition-all duration-200 ease-pssfp-out-expo',
              'focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30',
              isFilled
                ? 'border-[#4A2E67] bg-[#FAF7FF] text-[#4A2E67] shadow-pssfp-soft'
                : 'border-[#F4EFFA] bg-white text-[#333]',
              'focus:scale-105 focus:border-[#4A2E67] focus:shadow-pssfp-glow-prune',
              allFilled && 'border-emerald-400 bg-emerald-50/40 text-emerald-700',
            )}
          />
        );
      })}
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
