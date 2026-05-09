'use client';

import { useEffect, useRef } from 'react';
import { useInView, useMotionValue, useSpring, useReducedMotion } from 'framer-motion';
import { cn } from '../../lib/cn';

export interface NumberTickerProps {
  value: number;
  direction?: 'up' | 'down';
  delay?: number;
  decimalPlaces?: number;
  className?: string;
}

/**
 * Compteur animé qui s'incrémente vers une valeur cible quand visible.
 * Respecte prefers-reduced-motion (rend la valeur finale instantanément).
 */
export function NumberTicker({
  value,
  direction = 'up',
  delay = 0,
  decimalPlaces = 0,
  className,
}: NumberTickerProps): JSX.Element {
  const ref = useRef<HTMLSpanElement>(null);
  const reduceMotion = useReducedMotion();
  const motionValue = useMotionValue(direction === 'down' ? value : 0);
  const springValue = useSpring(motionValue, {
    damping: 60,
    stiffness: 100,
  });
  const isInView = useInView(ref, { once: true, margin: '0px' });

  useEffect(() => {
    if (reduceMotion) {
      if (ref.current) {
        ref.current.textContent = new Intl.NumberFormat('fr-FR', {
          minimumFractionDigits: decimalPlaces,
          maximumFractionDigits: decimalPlaces,
        }).format(value);
      }
      return;
    }
    if (!isInView) return;
    const timeout = setTimeout(() => {
      motionValue.set(direction === 'down' ? 0 : value);
    }, delay * 1000);
    return () => clearTimeout(timeout);
  }, [reduceMotion, motionValue, isInView, delay, value, direction, decimalPlaces]);

  useEffect(() => {
    if (reduceMotion) return;
    return springValue.on('change', (latest) => {
      if (ref.current) {
        ref.current.textContent = new Intl.NumberFormat('fr-FR', {
          minimumFractionDigits: decimalPlaces,
          maximumFractionDigits: decimalPlaces,
        }).format(Number(latest.toFixed(decimalPlaces)));
      }
    });
  }, [springValue, decimalPlaces, reduceMotion]);

  return (
    <span
      ref={ref}
      className={cn('inline-block tabular-nums', className)}
      aria-label={String(value)}
    >
      0
    </span>
  );
}
