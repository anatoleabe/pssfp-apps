import type { ReactNode } from 'react';
import { cn } from '../../lib/cn';

export interface SparklesProps {
  children: ReactNode;
  className?: string;
  color?: string;
  count?: number;
}

/**
 * Wrap un élément (ex: un mot dans un titre) avec des paillettes décoratives.
 * Server-renderable — positions calculées de manière déterministe à partir
 * d'un index stable. CSS pur, pas de framer-motion.
 *
 * Désactivé via @media prefers-reduced-motion (les paillettes restent
 * visibles mais ne pulsent plus).
 */
export function Sparkles({
  children,
  className,
  color = '#C9A227',
  count = 5,
}: SparklesProps): JSX.Element {
  // Positions/tailles déterministes basées sur l'index — stable SSR/CSR.
  const sparkles = Array.from({ length: count }, (_, i) => {
    const seed = (i + 1) * 2654435761;
    const x = ((seed % 90) + 5);
    const y = (((seed >> 8) % 80) + 10);
    const size = (((seed >> 16) % 6) + 4);
    const delay = ((seed >> 24) % 20) / 10;
    return { x, y, size, delay };
  });

  return (
    <span className={cn('relative inline-block pssfp-sparkles', className)}>
      {sparkles.map((s, i) => (
        <span
          key={i}
          aria-hidden="true"
          className="pssfp-sparkle pointer-events-none absolute"
          style={{
            left: `${s.x}%`,
            top: `${s.y}%`,
            width: `${s.size}px`,
            height: `${s.size}px`,
            animationDelay: `${s.delay}s`,
          }}
        >
          <svg viewBox="0 0 24 24" fill={color} style={{ width: '100%', height: '100%' }}>
            <path d="M12 0 L13.5 9 L24 12 L13.5 15 L12 24 L10.5 15 L0 12 L10.5 9 Z" />
          </svg>
        </span>
      ))}
      <span className="relative z-10">{children}</span>
      <style>{`
        .pssfp-sparkle {
          animation: pssfp-sparkle-pulse 2s ease-in-out infinite;
        }
        @keyframes pssfp-sparkle-pulse {
          0%, 100% { opacity: 0.4; transform: scale(0.85); }
          50% { opacity: 1; transform: scale(1.1); }
        }
        @media (prefers-reduced-motion: reduce) {
          .pssfp-sparkle { animation: none !important; opacity: 0.7 !important; }
        }
      `}</style>
    </span>
  );
}
