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
  accent: 'violet' | 'forest' | 'or' | 'ink';
}

// Rotation 4 accents — chacun apparaît une fois pour un rythme éditorial
// (violet PSSFP / forest institutionnel / gold accent / ink calme).
const STATS: ReadonlyArray<Stat> = [
  { key: 'promotions', value: 13, Icon: Layers, accent: 'forest' },
  { key: 'specialites', value: 5, Icon: GraduationCap, accent: 'or' },
  { key: 'diplomes', value: 1200, suffix: '+', Icon: Users, accent: 'violet' },
  { key: 'years', value: 10, suffix: '+', Icon: Trophy, accent: 'ink' },
];

const ACCENT_CLASSES = {
  violet: {
    pill: 'bg-[#F4EFFA] text-[#4A2E67]',
    halo: 'radial-gradient(circle at 30% 0%, rgba(74, 46, 103, 0.08) 0%, transparent 60%)',
    value: 'text-[#4A2E67]',
  },
  forest: {
    pill: 'bg-[#D6E4EC] text-[#0F3A4A]',
    halo: 'radial-gradient(circle at 30% 0%, rgba(15, 58, 74, 0.10) 0%, transparent 60%)',
    value: 'text-[#0F3A4A]',
  },
  or: {
    pill: 'bg-[#FBEFC9] text-[#9A7B12]',
    halo: 'radial-gradient(circle at 30% 0%, rgba(212, 175, 106, 0.12) 0%, transparent 60%)',
    value: 'pssfp-text-gradient-violet-or',
  },
  ink: {
    pill: 'bg-[#EFE9DF] text-[#14101A]',
    halo: 'radial-gradient(circle at 30% 0%, rgba(60, 60, 60, 0.06) 0%, transparent 60%)',
    value: 'text-[#14101A]',
  },
} as const;

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
      className="relative border-y border-[#D8C9A6] bg-[#FAF7F2]"
    >
      {/* Hairline gold éditorial */}
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-[#D4AF6A]/60 to-transparent"
      />

      <div className="mx-auto max-w-7xl px-6 py-16 md:py-20">
        {/* En-tête éditorial — eyebrow + Playfair headline + intro + ornement */}
        <BlurFade inView delay={0}>
          <header className="mx-auto mb-12 max-w-2xl text-center md:mb-16">
            <p className="pssfp-eyebrow">{t('eyebrow')}</p>
            <h2
              id="stats-heading"
              className="mt-4 font-heading font-bold text-pssfp-h2 text-[#1A1A1A]"
            >
              {t('heading')}{' '}
              <span className="relative inline-block">
                <span className="pssfp-text-gradient-violet-or">{t('headingAccent')}</span>
                <svg
                  aria-hidden="true"
                  className="absolute -bottom-2 left-0 w-full"
                  height="10"
                  viewBox="0 0 200 10"
                  fill="none"
                  xmlns="http://www.w3.org/2000/svg"
                >
                  <path
                    d="M2 8C50 3 150 3 198 8"
                    stroke="#D4AF6A"
                    strokeWidth="3"
                    strokeLinecap="round"
                  />
                </svg>
              </span>
            </h2>
            <p className="mx-auto mt-6 max-w-xl pssfp-body text-[#5C5566]">
              {t('intro')}
            </p>
            <div
              aria-hidden="true"
              className="mx-auto mt-8 flex w-32 items-center justify-center gap-3"
            >
              <div className="h-px flex-1 bg-gradient-to-r from-transparent to-[#D4AF6A]/50" />
              <div className="h-1.5 w-1.5 rounded-full bg-[#D4AF6A]" />
              <div className="h-px flex-1 bg-gradient-to-l from-transparent to-[#D4AF6A]/50" />
            </div>
          </header>
        </BlurFade>

        <ul className="grid grid-cols-2 gap-4 md:grid-cols-4 md:gap-6">
          {STATS.map((stat, index) => {
            const a = ACCENT_CLASSES[stat.accent];
            return (
              <li key={stat.key}>
                <BlurFade delay={index * 0.08} inView>
                  <div className="group relative h-full overflow-hidden rounded-pssfp-card border border-[#D8C9A6] bg-white p-6 shadow-pssfp-soft transition-all duration-300 ease-pssfp-out-expo hover:-translate-y-1 hover:border-transparent hover:shadow-pssfp-elevated">
                    <div
                      aria-hidden="true"
                      className="pointer-events-none absolute inset-0 -z-10 opacity-0 transition-opacity duration-500 group-hover:opacity-100"
                      style={{ background: a.halo }}
                    />

                    <span
                      aria-hidden="true"
                      className={`mb-4 inline-flex h-11 w-11 items-center justify-center rounded-xl transition-transform duration-300 ease-pssfp-out-expo group-hover:scale-110 ${a.pill}`}
                    >
                      <stat.Icon size={22} />
                    </span>

                    <p
                      className={`font-heading text-4xl font-bold leading-none md:text-5xl ${a.value}`}
                    >
                      <NumberTicker value={stat.value} delay={index * 0.1} />
                      {stat.suffix ?? ''}
                    </p>
                    <p className="mt-3 font-ui text-xs uppercase tracking-[0.16em] text-[#6B6B6B] md:text-sm">
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
