'use client';

import { motion, useReducedMotion } from 'framer-motion';
import type { JSX } from 'react';
import { cn } from '@/lib/cn';

export interface BackgroundPathsProps {
  /**
   * Tailwind text-color class controlling the SVG stroke color (`currentColor`).
   * Default = violet institutionnel PSSFP.
   */
  colorClassName?: string;
  /** Extra classes for the absolute wrapper. */
  className?: string;
  /** Base animation duration in seconds (jitter ±10s added per path). */
  durationSeconds?: number;
  /** Number of paths per direction (1 to 36). Lower = lighter / faster paint. */
  pathCount?: number;
}

interface FloatingPathsProps {
  position: number;
  count: number;
  durationSeconds: number;
  animate: boolean;
}

function FloatingPaths({ position, count, durationSeconds, animate }: FloatingPathsProps): JSX.Element {
  const paths = Array.from({ length: count }, (_, i) => ({
    id: i,
    d: `M-${380 - i * 5 * position} -${189 + i * 6}C-${
      380 - i * 5 * position
    } -${189 + i * 6} -${312 - i * 5 * position} ${216 - i * 6} ${
      152 - i * 5 * position
    } ${343 - i * 6}C${616 - i * 5 * position} ${470 - i * 6} ${
      684 - i * 5 * position
    } ${875 - i * 6} ${684 - i * 5 * position} ${875 - i * 6}`,
    width: 0.5 + i * 0.03,
  }));

  return (
    <svg
      className="h-full w-full"
      viewBox="0 0 696 316"
      fill="none"
      preserveAspectRatio="xMidYMid slice"
      aria-hidden="true"
      focusable="false"
    >
      {paths.map((path) => (
        <motion.path
          key={path.id}
          d={path.d}
          stroke="currentColor"
          strokeWidth={path.width}
          strokeOpacity={0.1 + path.id * 0.03}
          initial={animate ? { pathLength: 0.3, opacity: 0.6 } : false}
          animate={
            animate
              ? {
                  pathLength: 1,
                  opacity: [0.3, 0.6, 0.3],
                  pathOffset: [0, 1, 0],
                }
              : undefined
          }
          transition={
            animate
              ? {
                  duration: durationSeconds + Math.random() * 10,
                  repeat: Number.POSITIVE_INFINITY,
                  ease: 'linear',
                }
              : undefined
          }
        />
      ))}
    </svg>
  );
}

/**
 * Décor de fond animé — courbes SVG flottantes (framer-motion).
 *
 * Usage : à placer comme overlay dans un conteneur en `position: relative`.
 * Purement visuel : `aria-hidden`, `pointer-events-none`. Pas de contenu
 * sémantique, pas de texte (donc pas de i18n requise).
 *
 * Respecte `prefers-reduced-motion` (rend les courbes statiques).
 */
export function BackgroundPaths({
  colorClassName = 'text-[#4A2E67] dark:text-[#B084E8]',
  className,
  durationSeconds = 20,
  pathCount = 36,
}: BackgroundPathsProps = {}): JSX.Element {
  const prefersReducedMotion = useReducedMotion();
  const animate = !prefersReducedMotion;

  return (
    <div
      aria-hidden="true"
      className={cn(
        'pointer-events-none absolute inset-0 overflow-hidden',
        colorClassName,
        className,
      )}
    >
      <FloatingPaths position={1} count={pathCount} durationSeconds={durationSeconds} animate={animate} />
      <FloatingPaths position={-1} count={pathCount} durationSeconds={durationSeconds} animate={animate} />
    </div>
  );
}
