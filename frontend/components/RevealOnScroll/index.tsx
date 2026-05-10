'use client';

import { useEffect, useRef, useState, type ReactNode } from 'react';
import { cn } from '../../lib/cn';

interface RevealOnScrollProps {
  children: ReactNode;
  /** Délai d'apparition (ms) pour staggering. */
  delay?: number;
  /** Tag racine du wrapper. */
  as?: 'div' | 'section' | 'article' | 'li';
  className?: string;
}

/**
 * Révèle ses enfants quand ils entrent dans le viewport (IntersectionObserver).
 * Respecte `prefers-reduced-motion` via la classe `.pssfp-reveal` dans globals.css.
 */
export function RevealOnScroll({
  children,
  delay = 0,
  as = 'div',
  className,
}: RevealOnScrollProps): JSX.Element {
  const ref = useRef<HTMLElement | null>(null);
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    const node = ref.current;
    if (!node) return;
    if (typeof IntersectionObserver === 'undefined') {
      setVisible(true);
      return;
    }
    const observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting) {
            setVisible(true);
            observer.disconnect();
            break;
          }
        }
      },
      { threshold: 0.15, rootMargin: '0px 0px -10% 0px' },
    );
    observer.observe(node);
    return () => observer.disconnect();
  }, []);

  const Component = as;
  return (
    <Component
      ref={ref as never}
      className={cn('pssfp-reveal', visible && 'is-visible', className)}
      style={delay > 0 ? { transitionDelay: `${delay}ms` } : undefined}
    >
      {children}
    </Component>
  );
}
