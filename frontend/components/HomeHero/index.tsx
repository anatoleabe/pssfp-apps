import Link from 'next/link';
import { ArrowRight } from 'lucide-react';
import { getTranslations } from 'next-intl/server';

/**
 * Hero d'accueil pssfp.net.
 *
 * Composition sans photo de fond (assets `photos/campus/` non encore livrés
 * — cf. docs/assets-checklist.md §2). Direction visuelle : gradient violet
 * institutionnel + formes abstraites SVG + typographie editoriale.
 *
 * <!-- TODO replace background with assets-source/photos/campus/facade-messa-1.jpg -->
 */
export async function HomeHero(): Promise<JSX.Element> {
  const t = await getTranslations('home.hero');
  return (
    <section
      aria-labelledby="hero-heading"
      className="relative isolate overflow-hidden bg-gradient-to-br from-[#6B2FA0] via-[#7B3DAD] to-[#9B59B6] text-white"
    >
      {/* Formes abstraites décoratives, aria-hidden */}
      <div className="pointer-events-none absolute inset-0 -z-10" aria-hidden="true">
        <svg viewBox="0 0 1440 720" preserveAspectRatio="xMidYMid slice" className="h-full w-full opacity-30">
          <defs>
            <radialGradient id="halo" cx="80%" cy="20%" r="60%">
              <stop offset="0%" stopColor="#C9A227" stopOpacity="0.55" />
              <stop offset="100%" stopColor="#C9A227" stopOpacity="0" />
            </radialGradient>
            <pattern id="grid" width="60" height="60" patternUnits="userSpaceOnUse">
              <path d="M 60 0 L 0 0 0 60" fill="none" stroke="#FFFFFF" strokeOpacity="0.06" strokeWidth="1" />
            </pattern>
          </defs>
          <rect width="100%" height="100%" fill="url(#grid)" />
          <rect width="100%" height="100%" fill="url(#halo)" />
          <circle cx="1100" cy="480" r="220" fill="#FFFFFF" fillOpacity="0.04" />
          <circle cx="220" cy="120" r="80" fill="#FFFFFF" fillOpacity="0.08" />
        </svg>
      </div>

      <div className="mx-auto grid max-w-7xl gap-10 px-6 py-20 md:grid-cols-[3fr_2fr] md:py-28 lg:py-32">
        <div>
          <p className="font-ui text-sm uppercase tracking-widest text-[#FFE9B0]">
            {t('eyebrow')}
          </p>
          <h1
            id="hero-heading"
            className="mt-3 font-heading text-4xl font-bold leading-tight md:text-6xl"
          >
            {t('title')}
          </h1>
          <p className="mt-5 max-w-2xl text-lg text-white/90">{t('subtitle')}</p>
          <div className="mt-8 flex flex-wrap gap-3">
            <a
              href={process.env.NEXT_PUBLIC_CANDIDATURE_URL ?? '#'}
              data-testid="hero-cta-candidature"
              className="inline-flex h-12 items-center gap-2 rounded-md bg-[#C9A227] px-6 text-base font-medium text-[#1A0A2E] hover:bg-[#D9B237] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-[#6B2FA0]"
            >
              {t('ctaPrimary')}
              <ArrowRight size={16} aria-hidden="true" />
            </a>
            <Link
              href="/formations"
              className="inline-flex h-12 items-center gap-2 rounded-md border border-white/40 bg-white/10 px-6 text-base font-medium text-white hover:bg-white/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-[#6B2FA0]"
            >
              {t('ctaSecondary')}
            </Link>
          </div>
        </div>

        {/* Carte d'identité campus (lieu / promotion en cours) */}
        <div className="hidden md:flex md:items-end md:justify-end">
          <div className="rounded-2xl border border-white/20 bg-white/10 p-6 backdrop-blur-sm">
            <p className="text-xs uppercase tracking-widest text-[#FFE9B0]">{t('campusEyebrow')}</p>
            <p className="mt-2 font-heading text-2xl font-semibold">{t('campusName')}</p>
            <p className="mt-3 text-sm text-white/80">{t('campusBody')}</p>
            <dl className="mt-4 grid grid-cols-2 gap-3 text-sm">
              <div>
                <dt className="text-white/60">{t('promoLabel')}</dt>
                <dd className="font-semibold">P14 / 2026</dd>
              </div>
              <div>
                <dt className="text-white/60">{t('campusCity')}</dt>
                <dd className="font-semibold">Yaoundé, CM</dd>
              </div>
            </dl>
          </div>
        </div>
      </div>
    </section>
  );
}
