import Link from 'next/link';
import {
  ArrowRight,
  TrendingUp,
  Calculator,
  Building2,
  Handshake,
  ShieldCheck,
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

const SPECS: ReadonlyArray<Specialite> = [
  // Card phare en colonne 1-2 / ligne 1-2 (bento large)
  {
    slug: 'fiscalite-finance-comptabilite-publique',
    number: '02',
    titleKey: 'spec2',
    bodyKey: 'spec2Body',
    Icon: Calculator,
    featured: true,
  },
  {
    slug: 'economie-publique-gestion-publique',
    number: '01',
    titleKey: 'spec1',
    bodyKey: 'spec1Body',
    Icon: TrendingUp,
  },
  {
    slug: 'gouvernance-territoriale-finances-publiques-locales',
    number: '03',
    titleKey: 'spec3',
    bodyKey: 'spec3Body',
    Icon: Building2,
  },
  {
    slug: 'marches-publics-partenariats-public-prive',
    number: '04',
    titleKey: 'spec4',
    bodyKey: 'spec4Body',
    Icon: Handshake,
  },
  {
    slug: 'audit-controle-finances-publiques',
    number: '05',
    titleKey: 'spec5',
    bodyKey: 'spec5Body',
    Icon: ShieldCheck,
  },
];

/**
 * Bloc 5 spécialités en BentoGrid asymétrique (refonte PR R).
 *
 * Card phare (Fiscalité) en col-span-2 / row-span-2 avec gradient violet
 * et CTA proéminent ; 4 cards classiques en grille standard. Hover lift +
 * reveal animation sur chevron.
 */
export async function HomeSpecialites(): Promise<JSX.Element> {
  const t = await getTranslations('home.specialites');
  return (
    <section
      aria-labelledby="specialites-heading"
      data-testid="home-specialites"
      className="relative bg-[#FAF7FF]"
    >
      {/* Texture grain doux */}
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-0 opacity-[0.02]"
        style={{
          backgroundImage:
            'radial-gradient(circle at 1px 1px, #6B2FA0 1px, transparent 0)',
          backgroundSize: '32px 32px',
        }}
      />

      <div className="relative mx-auto max-w-7xl px-6 py-20 md:py-24">
        <header className="mb-12 max-w-3xl">
          <p className="pssfp-eyebrow">{t('eyebrow')}</p>
          <h2
            id="specialites-heading"
            className="mt-3 font-heading font-bold text-pssfp-h2 text-[#1A0A2E]"
          >
            {t('title')}
          </h2>
          <p className="mt-4 pssfp-lead">{t('intro')}</p>
        </header>

        <ul className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 lg:auto-rows-[minmax(220px,auto)]">
          {SPECS.map((spec) => {
            if (spec.featured) {
              return (
                <li key={spec.slug} className="lg:col-span-2 lg:row-span-2">
                  <Link
                    href={`/formations/specialites/${spec.slug}`}
                    data-testid={`spec-card-${spec.slug}`}
                    className="group relative flex h-full min-h-[280px] flex-col justify-between overflow-hidden rounded-pssfp-card bg-gradient-violet-deep p-8 text-white shadow-pssfp-elevated transition-all duration-300 ease-pssfp-out-expo hover:-translate-y-1 hover:shadow-pssfp-floating focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C9A227] focus-visible:ring-offset-2 md:p-10"
                  >
                    {/* Halo or décoratif */}
                    <div
                      aria-hidden="true"
                      className="pointer-events-none absolute -right-20 -top-20 h-72 w-72 rounded-full opacity-40 blur-3xl transition-opacity duration-500 group-hover:opacity-70"
                      style={{ background: 'radial-gradient(circle, #C9A227 0%, transparent 70%)' }}
                    />
                    {/* Pattern grille subtile */}
                    <div
                      aria-hidden="true"
                      className="pointer-events-none absolute inset-0 opacity-[0.06]"
                      style={{
                        backgroundImage:
                          'linear-gradient(white 1px, transparent 1px), linear-gradient(90deg, white 1px, transparent 1px)',
                        backgroundSize: '40px 40px',
                      }}
                    />

                    <div className="relative">
                      <span className="inline-flex items-center gap-3 text-xs uppercase tracking-[0.2em] text-[#FFE9B0]">
                        <span className="h-px w-8 bg-[#C9A227]" aria-hidden="true" />
                        Spécialité phare · {spec.number}
                      </span>
                      <span
                        aria-hidden="true"
                        className="mt-6 inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-white/15 text-[#FFE9B0] backdrop-blur-2xl transition-transform duration-300 ease-pssfp-out-expo group-hover:scale-110"
                      >
                        <spec.Icon size={28} />
                      </span>
                      <h3 className="mt-6 font-heading text-2xl font-bold leading-tight md:text-3xl">
                        {t(spec.titleKey)}
                      </h3>
                      <p className="mt-3 max-w-md text-base text-white/85 md:text-lg">
                        {t(spec.bodyKey)}
                      </p>
                    </div>
                    <span className="relative mt-6 inline-flex items-center gap-2 self-start rounded-full bg-gradient-or-soft px-5 py-2.5 text-sm font-semibold text-[#1A0A2E] shadow-pssfp-glow-or transition-all duration-200 group-hover:gap-3 group-hover:shadow-pssfp-floating">
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
                  className="group relative flex h-full min-h-[220px] flex-col overflow-hidden rounded-pssfp-card border border-[#EDE7F6] bg-white p-6 shadow-pssfp-soft transition-all duration-300 ease-pssfp-out-expo hover:-translate-y-1 hover:border-[#9B59B6]/40 hover:shadow-pssfp-elevated focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2"
                >
                  <div className="flex items-start justify-between">
                    <span
                      aria-hidden="true"
                      className="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-[#EDE7F6] text-[#6B2FA0] transition-all duration-300 ease-pssfp-out-expo group-hover:scale-110 group-hover:bg-gradient-violet group-hover:text-white"
                    >
                      <spec.Icon size={22} />
                    </span>
                    <span className="font-heading text-2xl font-bold text-[#C9A227]/60">
                      {spec.number}
                    </span>
                  </div>
                  <h3 className="mt-5 font-heading text-pssfp-h3 font-bold leading-snug text-[#1A0A2E]">
                    {t(spec.titleKey)}
                  </h3>
                  <p className="mt-2 grow text-sm leading-relaxed text-[#555]">
                    {t(spec.bodyKey)}
                  </p>
                  <span className="mt-5 inline-flex items-center gap-1.5 text-sm font-semibold text-[#6B2FA0] transition-all duration-200 group-hover:gap-2.5">
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
      </div>
    </section>
  );
}
