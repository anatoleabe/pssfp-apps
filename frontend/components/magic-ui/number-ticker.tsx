'use client';

import { useEffect, useRef, useState } from 'react';
import { useInView, useMotionValue, useSpring, useReducedMotion } from 'framer-motion';
import { cn } from '../../lib/cn';

export interface NumberTickerProps {
  value: number;
  direction?: 'up' | 'down';
  delay?: number;
  decimalPlaces?: number;
  className?: string;
}

const NUMBER_FORMATTER = new Intl.NumberFormat('fr-FR');

function formatNumber(n: number, decimalPlaces: number): string {
  return new Intl.NumberFormat('fr-FR', {
    minimumFractionDigits: decimalPlaces,
    maximumFractionDigits: decimalPlaces,
  }).format(Number(n.toFixed(decimalPlaces)));
}

/**
 * Compteur animé vers une valeur cible quand visible.
 *
 * Sprint S5.1 — corrige le bug du NumberTicker qui restait bloqué à 0
 * (ou à une valeur intermédiaire) en light mode initial :
 *   1. SSR + initial client render = valeur finale formatée (`finalText`).
 *      Garantit que même si le useInView ne déclenche jamais (rare, mais
 *      observé), le visiteur voit la vraie valeur.
 *   2. Au mount client, si !reduceMotion : reset à 0 puis anime au `delay`.
 *   3. Watchdog 1.8s après le set : force la valeur finale si le spring
 *      ne l'a pas atteinte (anti-blocage).
 *   4. prefers-reduced-motion : garde la valeur finale figée, pas d'anim.
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
  const motionValue = useMotionValue(value); // démarre à la valeur finale pour SSR
  const springValue = useSpring(motionValue, { damping: 60, stiffness: 100 });
  const isInView = useInView(ref, { once: true, amount: 0.1 });
  const [mounted, setMounted] = useState(false);

  // Marqueur de mount — distingue le render serveur du render client.
  useEffect(() => {
    setMounted(true);
  }, []);

  // Anime la valeur quand le ticker entre dans le viewport (client uniquement).
  useEffect(() => {
    if (!mounted) return;
    if (reduceMotion) {
      // Reduce motion : on force la valeur finale, sans animation.
      if (ref.current) {
        ref.current.textContent = formatNumber(value, decimalPlaces);
      }
      return;
    }
    if (!isInView) return;

    // Reset à 0 (ou à value si direction=down) puis anime.
    motionValue.set(direction === 'down' ? value : 0);
    if (ref.current) {
      ref.current.textContent = formatNumber(direction === 'down' ? value : 0, decimalPlaces);
    }
    const startTimeout = setTimeout(() => {
      motionValue.set(direction === 'down' ? 0 : value);
    }, delay * 1000);

    // Watchdog : si après 1.8s + delay le spring n'a pas atteint la valeur
    // finale, on la force. Sécurise les bugs d'animation observés en audit S5.
    const watchdog = setTimeout(() => {
      if (ref.current) {
        ref.current.textContent = formatNumber(value, decimalPlaces);
      }
      motionValue.set(value);
    }, delay * 1000 + 1800);

    return () => {
      clearTimeout(startTimeout);
      clearTimeout(watchdog);
    };
  }, [mounted, reduceMotion, motionValue, isInView, delay, value, direction, decimalPlaces]);

  // Met à jour le contenu textuel à chaque tick du spring.
  useEffect(() => {
    if (!mounted || reduceMotion) return;
    return springValue.on('change', (latest) => {
      if (ref.current) {
        ref.current.textContent = formatNumber(latest, decimalPlaces);
      }
    });
  }, [mounted, springValue, decimalPlaces, reduceMotion]);

  // Contenu initial (SSR + 1er render client) = valeur finale formatée.
  // Garantit qu'on ne voit jamais "0" si l'animation échoue.
  const finalText = formatNumber(value, decimalPlaces);

  return (
    <span
      ref={ref}
      className={cn('inline-block tabular-nums', className)}
      aria-label={NUMBER_FORMATTER.format(value)}
    >
      {finalText}
    </span>
  );
}
