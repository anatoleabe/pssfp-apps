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
 * Sprint S5.2 — fix régression #45 : le S5.1 armait le watchdog APRÈS le
 * early return sur `!isInView`. Si la card est wrappée dans `BlurFade`
 * (cf. `HomeStats`) qui démarre à opacity 0, l'IntersectionObserver peut
 * fire à `true` puis le reset à 0 écrase le SSR sans que l'animation ne
 * complète — résultat : visiteur bloqué à "0" ou valeur intermédiaire.
 *
 * Garanties désormais :
 *   1. SSR + 1er render client = valeur finale (`finalText` dans <span>).
 *   2. `prefers-reduced-motion: reduce` → valeur finale figée immédiatement.
 *   3. Sinon, **watchdog absolu 2.5 s armé AVANT toute condition** : force
 *      la valeur finale même si `isInView` ne fire jamais, ou si l'animation
 *      ne complète pas. Empêche tout blocage permanent.
 *   4. Si `isInView` fire : animation propre de 0 → value (ou inverse pour
 *      `direction=down`), watchdog couvre toujours les anomalies.
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
      // Reduce motion : valeur finale figée, pas d'animation.
      if (ref.current) {
        ref.current.textContent = formatNumber(value, decimalPlaces);
      }
      return;
    }

    // Watchdog ABSOLU : armé AVANT tout check `isInView`. Garantit que
    // la valeur finale est rendue dans tous les cas (BlurFade qui masque
    // la card, IntersectionObserver qui ne fire pas, spring qui freeze).
    // Sprint S5.2 fix #45 — le bug venait du fait que le watchdog était
    // armé après l'early return `!isInView`, donc jamais déclenché si
    // l'observer ne triggerait pas.
    const watchdog = setTimeout(() => {
      if (ref.current) {
        ref.current.textContent = formatNumber(value, decimalPlaces);
      }
      motionValue.set(value);
    }, 2500);

    if (!isInView) {
      // Pas (encore) visible : on garde la valeur finale du SSR.
      // Le watchdog reste armé au cas où `isInView` ne fire jamais.
      return () => clearTimeout(watchdog);
    }

    // Visible : reset à 0 (ou à `value` pour direction=down) puis anime.
    motionValue.set(direction === 'down' ? value : 0);
    if (ref.current) {
      ref.current.textContent = formatNumber(
        direction === 'down' ? value : 0,
        decimalPlaces,
      );
    }
    const startTimeout = setTimeout(() => {
      motionValue.set(direction === 'down' ? 0 : value);
    }, delay * 1000);

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
