import { cn } from '../../utils/cn';

export interface PssfpLogoProps {
  /** Hauteur en px (32, 40, 48, 56, 64). Defaults 40. */
  size?: number;
  className?: string;
  /** Title accessible. Lu par les AT — ne pas mettre "logo". */
  title?: string;
}

/**
 * Logo PSSFP officiel. Pointe sur l'asset SVG du dossier public/logos.
 *
 * Frontend (pssfp.net) le sert depuis /logos/pssfp.svg.
 * Bibliothèque et candidature peuvent le servir aussi depuis leur public/.
 *
 * Pour utiliser ailleurs, copier le SVG dans le public/logos/ de l'app.
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
        src="/logos/pssfp.svg"
        alt=""
        height={size}
        style={{ height: size, width: 'auto' }}
        className="block"
      />
    </span>
  );
}
