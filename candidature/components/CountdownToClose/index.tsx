'use client';

import { useEffect, useState } from 'react';
import { computeCountdown } from '@/lib/format/date';

export interface CountdownToCloseProps {
  closesAt: string;
  ariaLabel?: string;
}

const STATUS_BG: Record<string, string> = {
  green: 'bg-emerald-50 text-emerald-700 border-emerald-300',
  orange: 'bg-amber-50 text-amber-700 border-amber-300',
  red: 'bg-red-50 text-red-700 border-red-300',
  expired: 'bg-gray-100 text-gray-500 border-gray-300',
};

export function CountdownToClose({ closesAt, ariaLabel }: CountdownToCloseProps): JSX.Element {
  const [parts, setParts] = useState(() => computeCountdown(closesAt));

  useEffect(() => {
    const id = setInterval(() => setParts(computeCountdown(closesAt)), 1000);
    return () => clearInterval(id);
  }, [closesAt]);

  if (parts.status === 'expired') {
    return (
      <div
        role="status"
        aria-label={ariaLabel ?? 'La campagne est clôturée'}
        className={`inline-flex items-center gap-2 rounded-md border px-3 py-1.5 text-sm ${STATUS_BG.expired}`}
      >
        Campagne clôturée
      </div>
    );
  }

  return (
    <div
      role="status"
      aria-label={ariaLabel ?? `Il reste ${parts.days} jours avant clôture`}
      data-testid="countdown"
      className={`inline-flex items-center gap-2 rounded-md border px-3 py-1.5 text-sm font-medium ${STATUS_BG[parts.status]}`}
    >
      <span>
        J-{parts.days} · {String(parts.hours).padStart(2, '0')}:{String(parts.minutes).padStart(2, '0')}:{String(parts.seconds).padStart(2, '0')}
      </span>
    </div>
  );
}
