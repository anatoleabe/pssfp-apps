import type { ReactNode } from 'react';

type EyebrowTone = 'prune' | 'or' | 'petrole' | 'light';

interface EyebrowProps {
  children: ReactNode;
  tone?: EyebrowTone;
  className?: string;
  testId?: string;
}

/*
 * Sur-titre éditorial : filet or décoratif + texte DM Sans capitales.
 * Contrastes du texte : prune claire #5C3A7E 8,3:1 sur ivoire · or foncé #7A5A1C 5,9:1 sur ivoire ·
 * bleu pétrole 11,4:1 sur ivoire · or champagne #D4AF6A 7,5:1 sur la prune « deep » (fonds sombres).
 * Sur fond clair, l'or champagne (≈ 2:1) reste réservé au filet.
 */
const TONES: Record<EyebrowTone, string> = {
  prune: 'text-pssfp-prune-light',
  or: 'text-pssfp-or-dark',
  petrole: 'text-pssfp-bleu-petrole',
  light: 'text-pssfp-or',
};

export function Eyebrow({ children, tone = 'prune', className = '', testId }: EyebrowProps): JSX.Element {
  return (
    <p data-testid={testId} className={`flex items-center gap-3 font-ui text-xs font-semibold uppercase tracking-[0.18em] ${TONES[tone]} ${className}`}>
      <span aria-hidden="true" className="h-px w-8 shrink-0 bg-pssfp-or" />
      <span>{children}</span>
    </p>
  );
}
