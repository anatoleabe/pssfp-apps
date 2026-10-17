import type { ReactNode } from 'react';

interface EyebrowProps {
  children: ReactNode;
  tone?: 'or' | 'petrole';
  className?: string;
}

/*
 * Sur-titre éditorial : filet or décoratif + texte DM Sans capitales.
 * Le texte « or » utilise l'or foncé #7A5A1C (5,9:1 sur ivoire, 6,9:1 sur blanc) :
 * l'or champagne #D4AF6A n'atteint pas 4,5:1 sur fond clair et reste réservé au filet.
 */
export function Eyebrow({ children, tone = 'petrole', className = '' }: EyebrowProps): JSX.Element {
  return (
    <p
      className={`flex items-center gap-3 font-ui text-xs font-medium uppercase tracking-[0.18em] ${
        tone === 'or' ? 'text-[#7A5A1C]' : 'text-pssfp-bleu-petrole'
      } ${className}`}
    >
      <span aria-hidden="true" className="h-px w-8 shrink-0 bg-pssfp-or" />
      <span>{children}</span>
    </p>
  );
}
