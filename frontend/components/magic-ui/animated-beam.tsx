'use client';

import { useReducedMotion } from 'framer-motion';
import { cn } from '../../lib/cn';

export interface AnimatedBeamProps {
  className?: string;
  /** Couleur principale du faisceau (défaut violet PSSFP). */
  color?: string;
  /** Couleur d'accent (défaut or PSSFP). */
  accentColor?: string;
}

/**
 * Faisceaux décoratifs animés pour le hero. Pure SVG/CSS, pas de Framer Motion DOM —
 * léger et performant. Désactivé sur prefers-reduced-motion.
 */
export function AnimatedBeam({
  className,
  color = '#9B59B6',
  accentColor = '#C9A227',
}: AnimatedBeamProps): JSX.Element {
  const reduceMotion = useReducedMotion();
  return (
    <div
      aria-hidden="true"
      className={cn(
        'pointer-events-none absolute inset-0 overflow-hidden',
        className,
      )}
    >
      <svg
        viewBox="0 0 1200 600"
        preserveAspectRatio="xMidYMid slice"
        className="h-full w-full"
      >
        <defs>
          <linearGradient id="beam-gradient-1" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stopColor={color} stopOpacity="0" />
            <stop offset="50%" stopColor={color} stopOpacity="0.65" />
            <stop offset="100%" stopColor={accentColor} stopOpacity="0" />
          </linearGradient>
          <linearGradient id="beam-gradient-2" x1="100%" y1="0%" x2="0%" y2="100%">
            <stop offset="0%" stopColor={accentColor} stopOpacity="0" />
            <stop offset="50%" stopColor={accentColor} stopOpacity="0.4" />
            <stop offset="100%" stopColor={color} stopOpacity="0" />
          </linearGradient>
          <radialGradient id="beam-glow" cx="50%" cy="50%" r="50%">
            <stop offset="0%" stopColor={color} stopOpacity="0.35" />
            <stop offset="100%" stopColor={color} stopOpacity="0" />
          </radialGradient>
        </defs>

        <g
          style={
            reduceMotion
              ? undefined
              : { transformOrigin: '50% 50%', animation: 'pssfp-beam-pulse 8s ease-in-out infinite' }
          }
        >
          <path
            d="M -100 100 C 200 250, 600 150, 900 280 C 1100 360, 1300 200, 1400 350"
            stroke="url(#beam-gradient-1)"
            strokeWidth="2"
            fill="none"
          />
          <path
            d="M -100 400 C 250 250, 700 500, 1000 320 C 1200 200, 1300 450, 1400 300"
            stroke="url(#beam-gradient-2)"
            strokeWidth="1.5"
            fill="none"
          />
        </g>

        <ellipse cx="900" cy="200" rx="340" ry="220" fill="url(#beam-glow)" />
        <ellipse cx="200" cy="450" rx="260" ry="180" fill="url(#beam-glow)" opacity="0.6" />
      </svg>

      <style>{`
        @keyframes pssfp-beam-pulse {
          0%, 100% { transform: scale(1) translateX(0); opacity: 1; }
          50% { transform: scale(1.04) translateX(-12px); opacity: 0.85; }
        }
      `}</style>
    </div>
  );
}
