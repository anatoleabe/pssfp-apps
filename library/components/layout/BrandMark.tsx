import Image from 'next/image';
import { cn } from '@pssfp/ui';

/** Dimensions intrinsèques de `public/logos/pssfp.png` (logo officiel, commun aux trois applications). */
const LOGO_WIDTH = 564;
const LOGO_HEIGHT = 443;

interface BrandMarkProps {
  /** Texte alternatif ; vide quand le lien qui l'entoure porte déjà le nom. */
  alt: string;
  /** Hauteur rendue, en px (la largeur suit le ratio du logo). */
  height?: number;
  /** Sur fond sombre : pastille ivoire autour du logo (zone de protection, lisibilité du violet). */
  onDark?: boolean;
  priority?: boolean;
  className?: string;
}

/** Logo officiel du PSSFP (jamais un pictogramme de substitution). */
export function BrandMark({ alt, height = 44, onDark = false, priority = false, className }: BrandMarkProps): JSX.Element {
  const width = Math.round((height * LOGO_WIDTH) / LOGO_HEIGHT);
  const image = (
    <Image
      src="/logos/pssfp.png"
      alt={alt}
      width={LOGO_WIDTH}
      height={LOGO_HEIGHT}
      sizes={`${width}px`}
      priority={priority}
      style={{ height, width }}
      className="block shrink-0"
    />
  );
  if (!onDark) return <span className={cn('inline-flex shrink-0', className)}>{image}</span>;
  return <span className={cn('inline-flex shrink-0 rounded-xl bg-pssfp-ivoire p-2.5 shadow-pssfp-soft', className)}>{image}</span>;
}
