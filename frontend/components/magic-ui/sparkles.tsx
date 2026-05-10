'use client';

import { useEffect, useState, type ReactNode, type CSSProperties } from 'react';
import { useReducedMotion } from 'framer-motion';
import { cn } from '../../lib/cn';

interface Sparkle {
  id: string;
  x: number;
  y: number;
  size: number;
  delay: number;
}

export interface SparklesProps {
  children: ReactNode;
  className?: string;
  color?: string;
  count?: number;
}

/**
 * Wrap un élément (ex: un mot dans un titre) avec des paillettes décoratives.
 * Respecte prefers-reduced-motion (sparkles statiques).
 */
export function Sparkles({
  children,
  className,
  color = '#C9A227',
  count = 5,
}: SparklesProps): JSX.Element {
  const reduceMotion = useReducedMotion();
  const [sparkles, setSparkles] = useState<Sparkle[]>([]);

  useEffect(() => {
    const generated: Sparkle[] = Array.from({ length: count }).map((_, i) => ({
      id: `sparkle-${i}-${Math.random().toString(36).slice(2, 7)}`,
      x: Math.random() * 100,
      y: Math.random() * 100,
      size: Math.random() * 6 + 4,
      delay: Math.random() * 2,
    }));
    setSparkles(generated);
  }, [count]);

  return (
    <span className={cn('relative inline-block', className)}>
      {sparkles.map((s) => (
        <span
          key={s.id}
          aria-hidden="true"
          className={reduceMotion ? '' : 'animate-pulse'}
          style={
            {
              position: 'absolute',
              left: `${s.x}%`,
              top: `${s.y}%`,
              width: `${s.size}px`,
              height: `${s.size}px`,
              animationDelay: `${s.delay}s`,
              animationDuration: '2s',
              pointerEvents: 'none',
            } satisfies CSSProperties
          }
        >
          <svg
            viewBox="0 0 24 24"
            fill={color}
            style={{ width: '100%', height: '100%' }}
          >
            <path d="M12 0 L13.5 9 L24 12 L13.5 15 L12 24 L10.5 15 L0 12 L10.5 9 Z" />
          </svg>
        </span>
      ))}
      <span className="relative z-10">{children}</span>
    </span>
  );
}
