'use client';

import { useTranslations } from 'next-intl';
import { GraduationCap, Layers, Users, Trophy, type LucideIcon } from 'lucide-react';
import { NumberTicker } from '../magic-ui/number-ticker';
import { BlurFade } from '../magic-ui/blur-fade';

interface Stat {
  key: 'promotions' | 'specialites' | 'diplomes' | 'years';
  value: number;
  suffix?: string;
  Icon: LucideIcon;
  accent: 'violet' | 'or';
}

const STATS: ReadonlyArray<Stat> = [
  { key: 'promotions', value: 13, Icon: Layers, accent: 'violet' },
  { key: 'specialites', value: 5, Icon: GraduationCap, accent: 'or' },
  { key: 'diplomes', value: 1200, suffix: '+', Icon: Users, accent: 'violet' },
  { key: 'years', value: 10, suffix: '+', Icon: Trophy, accent: 'or' },
];

/**
 * Bloc 4 chiffres clés animés (refonte PR R).
 *
 * BentoGrid 4 colonnes (2x2 mobile) avec NumberTicker (Framer Motion spring),
 * icône Lucide en accent, gradient subtil, hover lift, BlurFade au scroll.
 */
export function HomeStats(): JSX.Element {
  const t = useTranslations('home.stats');

  return (
    <section
      aria-labelledby="stats-heading"
      data-testid="home-stats"
      className="relative border-y border-[#EDE7F6] bg-white"
    >
      {/* Bande décorative subtile */}
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-[#C9A227]/40 to-transparent"
      />

      <div className="mx-auto max-w-7xl px-6 py-16 md:py-20">
        <h2 id="stats-heading" className="sr-only">
          {t('heading')}
        </h2>
        <ul className="grid grid-cols-2 gap-4 md:grid-cols-4 md:gap-6">
          {STATS.map((stat, index) => {
            const isViolet = stat.accent === 'violet';
            return (
              <li key={stat.key}>
                <BlurFade delay={index * 0.08} inView>
                  <div className="group relative h-full overflow-hidden rounded-pssfp-card border border-[#EDE7F6] bg-white p-6 shadow-pssfp-soft transition-all duration-300 ease-pssfp-out-expo hover:-translate-y-1 hover:border-transparent hover:shadow-pssfp-elevated">
                    {/* Halo gradient au hover */}
                    <div
                      aria-hidden="true"
                      className="pointer-events-none absolute inset-0 -z-10 opacity-0 transition-opacity duration-500 group-hover:opacity-100"
                      style={{
                        background: isViolet
                          ? 'radial-gradient(circle at 30% 0%, rgba(107, 47, 160, 0.08) 0%, transparent 60%)'
                          : 'radial-gradient(circle at 30% 0%, rgba(201, 162, 39, 0.10) 0%, transparent 60%)',
                      }}
                    />

                    {/* Icône en pastille */}
                    <span
                      aria-hidden="true"
                      className={`mb-4 inline-flex h-11 w-11 items-center justify-center rounded-xl transition-transform duration-300 ease-pssfp-out-expo group-hover:scale-110 ${
                        isViolet
                          ? 'bg-[#EDE7F6] text-[#6B2FA0]'
                          : 'bg-[#FFF6E0] text-[#C9A227]'
                      }`}
                    >
                      <stat.Icon size={22} />
                    </span>

                    <p
                      className={`font-heading text-4xl font-bold leading-none md:text-5xl ${
                        isViolet ? 'text-[#6B2FA0]' : 'pssfp-text-gradient-violet-or'
                      }`}
                    >
                      <NumberTicker value={stat.value} delay={index * 0.1} />
                      {stat.suffix ?? ''}
                    </p>
                    <p className="mt-3 font-ui text-xs uppercase tracking-[0.16em] text-[#666] md:text-sm">
                      {t(stat.key)}
                    </p>
                  </div>
                </BlurFade>
              </li>
            );
          })}
        </ul>
      </div>
    </section>
  );
}
