import { cn } from '../../utils/cn';

export interface PssfpLogoProps {
  /** Hauteur en px (32, 40, 48, 56, 64). Defaults 40. */
  size?: number;
  className?: string;
  /** Title accessible. Lu par les AT — ne pas mettre "logo". */
  title?: string;
}

/**
 * Logo PSSFP officiel. Pointe sur l'asset PNG du dossier public/logos.
 *
 * Frontend (pssfp.net) le sert depuis /logos/pssfp.png — version PNG transparente
 * préférée au SVG (le SVG officiel a un fond blanc qui rend mal en dark mode).
 * Bibliothèque et candidature le servent aussi depuis leur public/.
 */
export function PssfpLogo({
  size = 40,
  className,
  title = 'PSSFP',
}: PssfpLogoProps): JSX.Element {
  return (
    <span
      className={cn('inline-flex items-center', className)}
      role="img"
      aria-label={title}
    >
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img
        src="/logos/pssfp.png"
        alt=""
        height={size}
        style={{ height: size, width: 'auto' }}
        className="block"
      />
    </span>
  );
}
