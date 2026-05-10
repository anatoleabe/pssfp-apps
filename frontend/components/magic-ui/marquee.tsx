import type { ReactNode } from 'react';
import { cn } from '../../lib/cn';

export interface MarqueeProps {
  className?: string;
  /** Inverse la direction (true = right→left). */
  reverse?: boolean;
  /** Met en pause au survol. */
  pauseOnHover?: boolean;
  /** Vertical au lieu de horizontal. */
  vertical?: boolean;
  /** Nombre de répétitions pour boucle continue (défaut 4). */
  repeat?: number;
  /** Durée animation en secondes (défaut 40s). */
  duration?: number;
  children: ReactNode;
}

/**
 * Bandeau défilant infini (Magic UI). Utilisé pour partenaires.
 * Désactivé via prefers-reduced-motion (CSS @media).
 */
export function Marquee({
  className,
  reverse = false,
  pauseOnHover = false,
  vertical = false,
  repeat = 4,
  duration = 40,
  children,
}: MarqueeProps): JSX.Element {
  return (
    <div
      className={cn(
        'group flex overflow-hidden p-2 [--gap:1rem] [gap:var(--gap)]',
        vertical ? 'flex-col' : 'flex-row',
        className,
      )}
      style={{ ['--duration' as string]: `${duration}s` }}
    >
      {Array.from({ length: repeat }).map((_, i) => (
        <div
          key={i}
          className={cn(
            'flex shrink-0 justify-around [gap:var(--gap)]',
            vertical
              ? 'animate-pssfp-marquee-vertical flex-col'
              : 'animate-pssfp-marquee flex-row',
            reverse && 'direction-reverse [animation-direction:reverse]',
            pauseOnHover && 'group-hover:[animation-play-state:paused]',
          )}
        >
          {children}
        </div>
      ))}
      <style>{`
        @media (prefers-reduced-motion: reduce) {
          .animate-pssfp-marquee, .animate-pssfp-marquee-vertical {
            animation: none !important;
          }
        }
      `}</style>
    </div>
  );
}
