import { cn } from '../../lib/cn';

export interface AnimatedBeamProps {
  className?: string;
  /** Couleur principale du faisceau (défaut violet PSSFP). */
  color?: string;
  /** Couleur d'accent (défaut or PSSFP). */
  accentColor?: string;
}

/**
 * Faisceaux décoratifs animés pour le hero. Pure SVG/CSS, server-renderable —
 * pas de Framer Motion, pas de hook navigateur. Désactivé via @media
 * prefers-reduced-motion dans le <style> embarqué.
 */
export function AnimatedBeam({
  className,
  color = '#5C3A7E',
  accentColor = '#D4AF6A',
}: AnimatedBeamProps): JSX.Element {
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
        <g className="pssfp-beam-group" style={{ transformOrigin: '50% 50%' }}>
          <path
            d="M -100 100 C 200 250, 600 150, 900 280 C 1100 360, 1300 200, 1400 350"
            stroke={color}
            strokeOpacity="0.4"
            strokeWidth="2"
            fill="none"
          />
          <path
            d="M -100 400 C 250 250, 700 500, 1000 320 C 1200 200, 1300 450, 1400 300"
            stroke={accentColor}
            strokeOpacity="0.25"
            strokeWidth="1.5"
            fill="none"
          />
        </g>

        <ellipse cx="900" cy="200" rx="340" ry="220" fill={color} fillOpacity="0.12" />
        <ellipse cx="200" cy="450" rx="260" ry="180" fill={color} fillOpacity="0.08" />
      </svg>

      <style>{`
        .pssfp-beam-group {
          animation: pssfp-beam-pulse 8s ease-in-out infinite;
        }
        @keyframes pssfp-beam-pulse {
          0%, 100% { transform: scale(1) translateX(0); opacity: 1; }
          50% { transform: scale(1.04) translateX(-12px); opacity: 0.85; }
        }
        @media (prefers-reduced-motion: reduce) {
          .pssfp-beam-group { animation: none !important; }
        }
      `}</style>
    </div>
  );
}
