'use client';

import { useEffect, useRef, useState } from 'react';
import { useTranslations } from 'next-intl';

interface Stat {
  key: string;
  value: number;
  suffix?: string;
}

const STATS: Stat[] = [
  { key: 'promotions', value: 13 },
  { key: 'specialites', value: 5 },
  { key: 'diplomes', value: 1200, suffix: '+' },
  { key: 'years', value: 10, suffix: '+' },
];

const DURATION_MS = 1400;

/**
 * Bloc 4 chiffres clés animés count-up au scroll (triggerOnce).
 *
 * Implémentation maison sans Framer Motion (économie ~30 kB au home page
 * bundle — important pour Lighthouse Performance ≥ 90 cf. CDC).
 */
export function HomeStats(): JSX.Element {
  const t = useTranslations('home.stats');
  const ref = useRef<HTMLDivElement>(null);
  const [played, setPlayed] = useState(false);

  useEffect(() => {
    if (played) return;
    const el = ref.current;
    if (!el) return;
    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            setPlayed(true);
            observer.disconnect();
          }
        });
      },
      { threshold: 0.3 },
    );
    observer.observe(el);
    return () => observer.disconnect();
  }, [played]);

  return (
    <section
      ref={ref}
      aria-labelledby="stats-heading"
      data-testid="home-stats"
      className="border-y border-[#EDE7F6] bg-white"
    >
      <div className="mx-auto max-w-7xl px-6 py-12 md:py-16">
        <h2 id="stats-heading" className="sr-only">
          {t('heading')}
        </h2>
        <ul className="grid grid-cols-2 gap-6 md:grid-cols-4">
          {STATS.map((stat) => (
            <li
              key={stat.key}
              className="rounded-lg border border-[#EDE7F6] bg-gradient-to-b from-white to-[#FAF7FF] p-5 text-center"
            >
              <p className="font-heading text-4xl font-bold text-[#6B2FA0] md:text-5xl">
                <CountUp target={stat.value} duration={DURATION_MS} active={played} />
                {stat.suffix ?? ''}
              </p>
              <p className="mt-2 font-ui text-sm uppercase tracking-wide text-[#666]">
                {t(stat.key)}
              </p>
            </li>
          ))}
        </ul>
      </div>
    </section>
  );
}

function CountUp({
  target,
  duration,
  active,
}: {
  target: number;
  duration: number;
  active: boolean;
}): JSX.Element {
  const [value, setValue] = useState(0);
  useEffect(() => {
    if (!active) return;
    let raf = 0;
    const start = performance.now();
    const tick = (now: number): void => {
      const elapsed = now - start;
      const progress = Math.min(1, elapsed / duration);
      // ease-out cubic
      const eased = 1 - Math.pow(1 - progress, 3);
      setValue(Math.round(target * eased));
      if (progress < 1) {
        raf = requestAnimationFrame(tick);
      }
    };
    raf = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(raf);
  }, [active, target, duration]);

  return <>{new Intl.NumberFormat('fr-FR').format(value)}</>;
}
