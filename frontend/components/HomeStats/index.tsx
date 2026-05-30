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

// Palette accent par stat — light + dark mode (S5.4).
// Hex dark mode alignés avec SiteHeader/MegaMenu (de facto palette projet).
const ACCENT_CLASSES = {
  violet: {
    pill: 'bg-[#F4EFFA] text-[#4A2E67] dark:bg-[#4A2E67]/30 dark:text-[#B084E8]',
    halo: 'radial-gradient(circle at 30% 0%, rgba(74, 46, 103, 0.08) 0%, transparent 60%)',
    value: 'text-[#4A2E67] dark:text-[#B084E8]',
  },
  forest: {
    pill: 'bg-[#D6E4EC] text-[#0F3A4A] dark:bg-[#0F3A4A]/45 dark:text-[#7FB0C4]',
    halo: 'radial-gradient(circle at 30% 0%, rgba(15, 58, 74, 0.10) 0%, transparent 60%)',
    value: 'text-[#0F3A4A] dark:text-[#7FB0C4]',
  },
  or: {
    pill: 'bg-[#FBEFC9] text-[#9A7B12] dark:bg-[#D4AF6A]/20 dark:text-[#E5C788]',
    halo: 'radial-gradient(circle at 30% 0%, rgba(212, 175, 106, 0.12) 0%, transparent 60%)',
    value: 'pssfp-text-gradient-violet-or dark:text-[#E5C788]',
  },
  ink: {
    pill: 'bg-[#EFE9DF] text-[#14101A] dark:bg-[#3A2F48]/55 dark:text-[#F5EFE3]',
    halo: 'radial-gradient(circle at 30% 0%, rgba(60, 60, 60, 0.06) 0%, transparent 60%)',
    value: 'text-[#14101A] dark:text-[#F5EFE3]',
  },
} as const;

/**
 * Bloc 4 chiffres clés animés.
 *
 * S5.4 — Layout horizontal "icône GAUCHE + chiffre DROITE côte à côte",
 * label dessous : élimine le vide vertical des cards. Dark mode validé
 * (palette alignée SiteHeader/MegaMenu).
 *
 * Conserve : NumberTicker spring, BlurFade scroll, hover lift + halo radial,
 * hairline or éditoriale, ornement central sous l'eyebrow.
 */
export function HomeStats(): JSX.Element {
  const t = useTranslations('home.stats');

  return (
    <section
      aria-labelledby="stats-heading"
      data-testid="home-stats"
      className="relative border-y border-[#D8C9A6] bg-[#FAF7F2] dark:border-[#3A2F48] dark:bg-[#14101A]"
    >
      {/* Hairline gold éditorial */}
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-[#D4AF6A]/60 to-transparent dark:via-[#D4AF6A]/45"
      />

      <div className="mx-auto max-w-7xl px-6 py-16 md:py-20">
        {/* En-tête éditorial — eyebrow + Playfair headline + intro + ornement */}
        <BlurFade inView delay={0}>
          <header className="mx-auto mb-12 max-w-2xl text-center md:mb-16">
            <p className="pssfp-eyebrow">{t('eyebrow')}</p>
            <h2
              id="stats-heading"
              className="mt-4 font-heading font-bold text-pssfp-h2 text-[#1A1A1A] dark:text-[#F5EFE3]"
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
            <p className="mx-auto mt-6 max-w-xl pssfp-body text-[#5C5566] dark:text-[#B5A8C8]">
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
                  <div className="group relative h-full overflow-hidden rounded-pssfp-card border border-[#D8C9A6] bg-white p-5 shadow-pssfp-soft transition-all duration-300 ease-pssfp-out-expo hover:-translate-y-1 hover:border-transparent hover:shadow-pssfp-elevated dark:border-[#3A2F48] dark:bg-[#1F1A28]">
                    <div
                      aria-hidden="true"
                      className="pointer-events-none absolute inset-0 -z-10 opacity-0 transition-opacity duration-500 group-hover:opacity-100"
                      style={{ background: a.halo }}
                    />

                    {/*
                      Pair horizontal icône GAUCHE / chiffre DROITE — élimine
                      le vide vertical signalé par Anatole S5.3 → S5.4.
                    */}
                    <div className="flex items-center gap-4">
                      <span
                        aria-hidden="true"
                        className={`inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-xl transition-transform duration-300 ease-pssfp-out-expo group-hover:scale-110 md:h-14 md:w-14 ${a.pill}`}
                      >
                        <stat.Icon size={24} />
                      </span>
                      <p
                        className={`font-heading text-4xl font-bold leading-none tabular-nums md:text-5xl ${a.value}`}
                      >
                        <NumberTicker value={stat.value} delay={index * 0.1} />
                        {stat.suffix ?? ''}
                      </p>
                    </div>

                    {/* Label sous le pair (left-aligned). */}
                    <p className="mt-3 font-ui text-xs font-medium uppercase tracking-[0.18em] text-[#6B6B6B] dark:text-[#B5A8C8] md:text-[13px]">
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
