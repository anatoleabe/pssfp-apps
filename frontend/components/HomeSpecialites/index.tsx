import Link from 'next/link';
import {
  ArrowRight,
  TrendingUp,
  Calculator,
  Building2,
  Handshake,
  ShieldCheck,
  GraduationCap,
  Award,
  Globe2,
  type LucideIcon,
} from 'lucide-react';
import { getTranslations } from 'next-intl/server';

interface Specialite {
  slug: string;
  number: string;
  titleKey: 'spec1' | 'spec2' | 'spec3' | 'spec4' | 'spec5';
  bodyKey: 'spec1Body' | 'spec2Body' | 'spec3Body' | 'spec4Body' | 'spec5Body';
  Icon: LucideIcon;
  /** Card "phare" en gros sur le bento. */
  featured?: boolean;
}

// Slugs alignés avec FormationsPagesSeeder (Sprint S5 PR X) : « metiers-* »
// conformes au catalogue officiel PSSFP.
const SPECS: ReadonlyArray<Specialite> = [
  // Card phare en colonne 1-2, sans allonger artificiellement la grille.
  {
    slug: 'metiers-fiscalite-comptabilite',
    number: '04',
    titleKey: 'spec2',
    bodyKey: 'spec2Body',
    Icon: Calculator,
    featured: true,
  },
  {
    slug: 'metiers-budgetaires',
    number: '01',
    titleKey: 'spec1',
    bodyKey: 'spec1Body',
    Icon: TrendingUp,
  },
  {
    slug: 'metiers-gouvernance-territoriale-decentralisation',
    number: '02',
    titleKey: 'spec3',
    bodyKey: 'spec3Body',
    Icon: Building2,
  },
  {
    slug: 'metiers-commande-publique',
    number: '03',
    titleKey: 'spec4',
    bodyKey: 'spec4Body',
    Icon: Handshake,
  },
  {
    slug: 'metiers-audit-controle',
    number: '05',
    titleKey: 'spec5',
    bodyKey: 'spec5Body',
    Icon: ShieldCheck,
  },
];

interface ShortFormat {
  href: string;
  Icon: LucideIcon;
  labelKey: 'continueLabel' | 'certifLabel' | 'seminairesLabel';
  descKey: 'continueDesc' | 'certifDesc' | 'seminairesDesc';
  metaKey: 'continueMeta' | 'certifMeta' | 'seminairesMeta';
  ctaKey: 'continueCta' | 'certifCta' | 'seminairesCta';
  accent: 'forest' | 'or' | 'violet';
}

const SHORT_FORMATS: ReadonlyArray<ShortFormat> = [
  {
    href: '/formations/formation-continue',
    Icon: GraduationCap,
    labelKey: 'continueLabel',
    descKey: 'continueDesc',
    metaKey: 'continueMeta',
    ctaKey: 'continueCta',
    accent: 'forest',
  },
  {
    href: '/formations/certifications',
    Icon: Award,
    labelKey: 'certifLabel',
    descKey: 'certifDesc',
    metaKey: 'certifMeta',
    ctaKey: 'certifCta',
    accent: 'or',
  },
  {
    href: '/formations/seminaires',
    Icon: Globe2,
    labelKey: 'seminairesLabel',
    descKey: 'seminairesDesc',
    metaKey: 'seminairesMeta',
    ctaKey: 'seminairesCta',
    accent: 'violet',
  },
];

const SHORT_ACCENT_CLASSES = {
  forest: {
    iconBg: 'bg-[#D6E4EC] text-[#0F3A4A] group-hover:bg-[#0F3A4A] group-hover:text-white',
    cta: 'text-[#0F3A4A]',
    border: 'hover:border-[#0F3A4A]/40',
  },
  or: {
    iconBg: 'bg-[#FBEFC9] text-[#9A7B12] group-hover:bg-[#D4AF6A] group-hover:text-[#14101A]',
    cta: 'text-[#9A7B12]',
    border: 'hover:border-[#D4AF6A]/60',
  },
  violet: {
    iconBg: 'bg-[#F4EFFA] text-[#4A2E67] group-hover:bg-[#4A2E67] group-hover:text-white',
    cta: 'text-[#4A2E67]',
    border: 'hover:border-[#4A2E67]/40',
  },
} as const;

/**
 * Bloc Formations — refonte UX/UI Pro Max.
 *
 * Architecture éditoriale en deux niveaux :
 *  1. Header éditorial (eyebrow + titre Playfair + accent plat + intro)
 *  2. BentoGrid 5 spécialités (1 phare fond ink + 4 cards classiques)
 *  3. Bande "Au-delà du Master" — 3 cards Formats courts (continue / certifs / séminaires)
 *
 * Aligne le home sur le catalogue officiel : Master + Continue + Certifications + Séminaires.
 */
export async function HomeSpecialites(): Promise<JSX.Element> {
  const t = await getTranslations('home.specialites');

  return (
    <section
      aria-labelledby="specialites-heading"
      data-testid="home-specialites"
      className="relative bg-[#F4F0EA] dark:bg-[#14101A]"
    >
      {/* Teinte grain douce — ink (light) / ivoire (dark) */}
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-0 bg-[#14101A] opacity-[0.04] dark:bg-[#F5EFE3] dark:opacity-[0.06]"
      />
      {/* Hairline gold haut + bas */}
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-x-0 top-0 h-px bg-[#D4AF6A]/50 dark:bg-[#D4AF6A]/35"
      />
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-x-0 bottom-0 h-px bg-[#D4AF6A]/50 dark:bg-[#D4AF6A]/35"
      />

      <div className="relative mx-auto max-w-7xl px-6 py-16 md:py-20">
        {/* ─────────────── Header éditorial unifié ─────────────── */}
        <header className="mx-auto mb-10 max-w-3xl text-center md:mb-12">
          <p className="pssfp-eyebrow">{t('eyebrow')}</p>
          <h2
            id="specialites-heading"
            className="mt-4 font-heading font-bold text-pssfp-h2 text-[#14101A] dark:text-[#F5EFE3]"
          >
            {t('title')}{' '}
            <span className="relative inline-block">
              <span className="text-[#4A2E67] dark:text-[#E5C788]">{t('titleAccent')}</span>
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
          <p className="mx-auto mt-6 max-w-2xl pssfp-lead dark:text-[#B5A8C8]">{t('intro')}</p>
        </header>

        {/* ─────────────── Niveau 1 : 5 spécialités du Master ─────────────── */}
        <div className="mb-8 flex items-center gap-4">
          <div
            aria-hidden="true"
            className="h-px flex-1 bg-[#D4AF6A]/30 dark:bg-[#D4AF6A]/25"
          />
          <p className="pssfp-eyebrow text-[#4A2E67] dark:text-[#B084E8]">{t('specialitiesEyebrow')}</p>
          <div
            aria-hidden="true"
            className="h-px flex-1 bg-[#D4AF6A]/30 dark:bg-[#D4AF6A]/25"
          />
        </div>

        <ul className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 lg:auto-rows-[minmax(190px,auto)]">
          {SPECS.map((spec) => {
            if (spec.featured) {
              return (
                <li key={spec.slug} className="lg:col-span-2">
                  <Link
                    href={`/formations/specialites/${spec.slug}`}
                    data-testid={`spec-card-${spec.slug}`}
                    className="group relative flex h-full min-h-[260px] flex-col overflow-hidden rounded-pssfp-card bg-[#14101A] p-6 text-white shadow-pssfp-elevated transition-all duration-300 ease-pssfp-out-expo hover:-translate-y-1 hover:shadow-pssfp-floating focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#D4AF6A] focus-visible:ring-offset-2 md:p-7"
                  >
                    {/* Voile clair subtil */}
                    <div
                      aria-hidden="true"
                      className="pointer-events-none absolute inset-0 bg-white opacity-[0.03]"
                    />
                    {/*
                      Icône repositionnée en arrière-plan décoratif bas-droite —
                      S5.4 : remplace l'icône `h-14` qui flottait en haut-gauche
                      et laissait un vide central. Reste reconnaissable mais ne
                      détourne plus le regard du titre.
                    */}
                    <spec.Icon
                      aria-hidden="true"
                      size={180}
                      strokeWidth={1.25}
                      className="pointer-events-none absolute -bottom-6 -right-6 text-[#D4AF6A] opacity-[0.08] transition-opacity duration-500 group-hover:opacity-[0.14]"
                    />

                    <div className="relative">
                      <span className="inline-flex items-center gap-3 text-xs uppercase tracking-[0.2em] text-[#FFE9B0]">
                        <span className="h-px w-8 bg-[#D4AF6A]" aria-hidden="true" />
                        {t('featuredLabel')} · {spec.number}
                      </span>
                      <h3 className="mt-5 font-heading text-2xl font-bold leading-tight md:text-3xl">
                        {t(spec.titleKey)}
                      </h3>
                      <p className="mt-3 max-w-md text-base text-white/85 md:text-lg">
                        {t(spec.bodyKey)}
                      </p>

                      {/*
                        3 bullets "Compétences clés" — comblent le vide central
                        signalé S5.4 et donnent du contenu éditorial scannable
                        avant la CTA. Puces or alignées charte.
                      */}
                      <div className="hidden sm:block">
                        <p className="mt-6 font-ui text-[11px] font-medium uppercase tracking-[0.2em] text-[#D4AF6A]/85">
                          {t('featuredHighlightsLabel')}
                        </p>
                        <ul className="mt-3 space-y-2">
                          {(['featuredHighlight1', 'featuredHighlight2', 'featuredHighlight3'] as const).map(
                            (key) => (
                              <li
                                key={key}
                                className="flex items-center gap-3 text-sm text-white/90"
                              >
                                <span
                                  aria-hidden="true"
                                  className="h-1.5 w-1.5 shrink-0 rounded-full bg-[#D4AF6A]"
                                />
                                {t(key)}
                              </li>
                            ),
                          )}
                        </ul>
                      </div>
                    </div>
                    <span className="relative mt-7 inline-flex items-center gap-2 self-start rounded-full bg-[#D4AF6A] px-5 py-2.5 text-sm font-semibold text-[#14101A] shadow-pssfp-glow-or transition-all duration-200 group-hover:gap-3 group-hover:shadow-pssfp-floating">
                      {t('readMore')}
                      <ArrowRight
                        size={16}
                        aria-hidden="true"
                        className="transition-transform duration-200 group-hover:translate-x-0.5"
                      />
                    </span>
                  </Link>
                </li>
              );
            }
            return (
              <li key={spec.slug}>
                <Link
                  href={`/formations/specialites/${spec.slug}`}
                  data-testid={`spec-card-${spec.slug}`}
                  className="group relative flex h-full min-h-[164px] flex-col overflow-hidden rounded-pssfp-card border border-[#D8C9A6] bg-white p-4 shadow-pssfp-soft transition-all duration-300 ease-pssfp-out-expo hover:-translate-y-1 hover:border-[#D4AF6A]/50 hover:shadow-pssfp-elevated focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2 sm:min-h-[190px] sm:p-5 dark:border-[#3A2F48] dark:bg-[#1F1A28] dark:hover:border-[#D4AF6A]/60"
                >
                  <div className="flex items-start justify-between">
                    <span
                      aria-hidden="true"
                      className="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-[#EFE9DF] text-[#14101A] transition-all duration-300 ease-pssfp-out-expo group-hover:scale-110 group-hover:bg-[#0F3A4A] group-hover:text-white dark:bg-[#3A2F48]/60 dark:text-[#E5C788]"
                    >
                      <spec.Icon size={22} />
                    </span>
                    <span className="font-heading text-2xl font-bold text-[#D4AF6A]/70 dark:text-[#D4AF6A]/60">
                      {spec.number}
                    </span>
                  </div>
                  <h3 className="mt-4 font-heading text-pssfp-h3 font-bold leading-snug text-[#14101A] dark:text-[#F5EFE3]">
                    {t(spec.titleKey)}
                  </h3>
                  <p className="mt-2 hidden grow text-sm leading-relaxed text-[#6B6B6B] sm:block dark:text-[#B5A8C8]">
                    {t(spec.bodyKey)}
                  </p>
                  <span className="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-[#0F3A4A] transition-all duration-200 group-hover:gap-2.5 dark:text-[#7FB0C4]">
                    {t('readMore')}
                    <ArrowRight
                      size={14}
                      aria-hidden="true"
                      className="transition-transform duration-200 group-hover:translate-x-0.5"
                    />
                  </span>
                </Link>
              </li>
            );
          })}
        </ul>

        {/* ─────────────── Niveau 2 : Au-delà du Master (formats courts) ─────────────── */}
        <div className="mt-12 md:mt-14">
          <header className="mb-6 max-w-2xl">
            <p className="pssfp-eyebrow">{t('shortFormatsEyebrow')}</p>
            <h3 className="mt-3 font-heading text-pssfp-h3 font-bold text-[#14101A] md:text-3xl dark:text-[#F5EFE3]">
              {t('shortFormatsTitle')}
            </h3>
            <p className="mt-3 pssfp-body text-[#6B6B6B] dark:text-[#B5A8C8]">{t('shortFormatsIntro')}</p>
          </header>

          <ul className="grid grid-cols-1 gap-4 md:grid-cols-3 md:gap-6">
            {SHORT_FORMATS.map((fmt) => {
              const a = SHORT_ACCENT_CLASSES[fmt.accent];
              return (
                <li key={fmt.href}>
                  <Link
                    href={fmt.href}
                    data-testid={`short-format-${fmt.labelKey}`}
                    className={`group relative flex h-full flex-col overflow-hidden rounded-pssfp-card border border-[#D8C9A6] bg-white/80 p-4 shadow-pssfp-soft backdrop-blur-sm transition-all duration-300 ease-pssfp-out-expo hover:-translate-y-1 hover:bg-white hover:shadow-pssfp-elevated focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2 sm:p-5 dark:border-[#3A2F48] dark:bg-[#1F1A28]/80 dark:hover:bg-[#1F1A28] ${a.border}`}
                  >
                    <div className="flex items-center gap-3">
                      <span
                        aria-hidden="true"
                        className={`inline-flex h-11 w-11 items-center justify-center rounded-xl transition-all duration-300 ease-pssfp-out-expo group-hover:scale-110 ${a.iconBg}`}
                      >
                        <fmt.Icon size={20} />
                      </span>
                      <span className="font-ui text-xs uppercase tracking-[0.16em] text-[#6B6B6B] dark:text-[#B5A8C8]">
                        {t(fmt.metaKey)}
                      </span>
                    </div>
                    <h4 className="mt-5 font-heading text-lg font-bold leading-snug text-[#14101A] dark:text-[#F5EFE3]">
                      {t(fmt.labelKey)}
                    </h4>
                    <p className="mt-2 hidden grow text-sm leading-relaxed text-[#6B6B6B] sm:block dark:text-[#B5A8C8]">
                      {t(fmt.descKey)}
                    </p>
                    <span
                      className={`mt-5 inline-flex items-center gap-1.5 text-sm font-semibold transition-all duration-200 group-hover:gap-2.5 ${a.cta} dark:text-[#E5C788]`}
                    >
                      {t(fmt.ctaKey)}
                      <ArrowRight
                        size={14}
                        aria-hidden="true"
                        className="transition-transform duration-200 group-hover:translate-x-0.5"
                      />
                    </span>
                  </Link>
                </li>
              );
            })}
          </ul>
        </div>
      </div>
    </section>
  );
}
