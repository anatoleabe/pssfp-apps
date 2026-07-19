import Link from 'next/link';
import { ArrowRight, MapPin } from 'lucide-react';
import { getTranslations } from 'next-intl/server';
import { AnimatedBeam } from '../magic-ui/animated-beam';
import { Sparkles } from '../magic-ui/sparkles';

/**
 * Hero d'accueil pssfp.org — refonte PR R.
 *
 * Direction visuelle : fond plat lavande subtil + faisceaux
 * SVG animés (AnimatedBeam) + sparkles or sur le mot clé "excellence" +
 * CTA prune/or plat avec shadow floating + carte campus en glass card.
 *
 * <!-- TODO replace background with assets-source/photos/campus/facade-messa-1.jpg -->
 */
export async function HomeHero(): Promise<JSX.Element> {
  const t = await getTranslations('home.hero');

  // On découpe le titre pour insérer Sparkles sur "excellence".
  const title = t('title');
  const splitMatch = title.match(/^(.*?)(excellence)(.*)$/i);
  const titleNodes = splitMatch
    ? (
        <>
          {splitMatch[1]}
          <Sparkles color="#D4AF6A" count={6}>
            <span className="text-[#4A2E67] dark:text-[#E5C788]">{splitMatch[2]}</span>
          </Sparkles>
          {splitMatch[3]}
        </>
      )
    : title;

  return (
    <section
      aria-labelledby="hero-heading"
      className="relative isolate overflow-hidden bg-[#FAF7F2] dark:bg-[#14091F]"
    >
      {/* Faisceaux décoratifs animés (aria-hidden) */}
      <AnimatedBeam className="opacity-70" />

      {/* Grain léger pour atmosphère */}
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-0 -z-10 bg-[#4A2E67] opacity-[0.025]"
      />

      <div className="mx-auto grid max-w-7xl gap-12 px-6 py-20 md:grid-cols-[3fr_2fr] md:py-28 lg:py-32">
        <div className="relative">
          <p className="pssfp-eyebrow">{t('eyebrow')}</p>
          <h1
            id="hero-heading"
            className="mt-4 font-heading font-bold text-pssfp-h1 text-[#1A1A1A] dark:text-[#FAF7F2]"
          >
            {titleNodes}
          </h1>
          <p className="mt-6 max-w-2xl pssfp-lead">{t('subtitle')}</p>
          <div className="mt-10 flex flex-wrap gap-3">
            <a
              href={process.env.NEXT_PUBLIC_CANDIDATURE_URL ?? '#'}
              data-testid="hero-cta-candidature"
              className="group relative inline-flex h-14 items-center gap-2 overflow-hidden rounded-pssfp-button bg-[#4A2E67] px-7 text-base font-semibold text-white shadow-pssfp-floating transition-all duration-200 ease-pssfp-out-expo hover:-translate-y-0.5 hover:bg-[#3A2452] hover:shadow-pssfp-glow-or focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#D4AF6A] focus-visible:ring-offset-2"
            >
              <span className="relative">{t('ctaPrimary')}</span>
              <ArrowRight
                size={18}
                aria-hidden="true"
                className="relative transition-transform duration-200 group-hover:translate-x-0.5"
              />
            </a>
            <Link
              href="/formations"
              className="inline-flex h-14 items-center gap-2 rounded-pssfp-button border-2 border-[#4A2E67] bg-white/80 px-7 text-base font-semibold text-[#4A2E67] backdrop-blur-2xs transition-all duration-200 ease-pssfp-out-expo hover:bg-[#4A2E67] hover:text-white hover:shadow-pssfp-elevated focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2 dark:border-[#B084E8] dark:bg-[#1F0E2E]/60 dark:text-[#B084E8] dark:hover:bg-[#B084E8] dark:hover:text-[#14091F] dark:focus-visible:ring-[#E5C788]"
            >
              {t('ctaSecondary')}
            </Link>
          </div>

          {/* Trust signals léger */}
          <ul className="mt-12 flex flex-wrap items-center gap-x-8 gap-y-3 text-xs text-[#666] dark:text-[#B5A8C8]">
            <li className="flex items-center gap-2">
              <span className="h-2 w-2 rounded-full bg-[#2E7D32] animate-pssfp-pulse-prune" aria-hidden="true" />
              <span className="font-medium">Inscriptions P14 ouvertes</span>
            </li>
            <li className="flex items-center gap-2">
              <span aria-hidden="true">·</span>
              <span>Accréditation CAMES</span>
            </li>
            <li className="flex items-center gap-2">
              <span aria-hidden="true">·</span>
              <span>13 promotions formées</span>
            </li>
          </ul>
        </div>

        {/* Carte campus en glass-card */}
        <div className="hidden md:flex md:items-end md:justify-end">
          <div className="relative w-full max-w-sm rounded-pssfp-card border border-white/40 bg-white/60 p-7 shadow-pssfp-floating backdrop-blur-2xl dark:border-[#3A2A55] dark:bg-[#1F0E2E]/70">
            {/* Halo or derrière la card */}
            <div
              aria-hidden="true"
              className="pointer-events-none absolute -inset-1 -z-10 rounded-pssfp-card bg-[#D4AF6A]/20 opacity-30 blur-xl"
            />
            <p className="pssfp-eyebrow">{t('campusEyebrow')}</p>
            <p className="mt-3 font-heading text-pssfp-h3 font-bold text-[#1A1A1A] dark:text-[#FAF7F2]">
              {t('campusName')}
            </p>
            <p className="mt-3 text-sm leading-relaxed text-[#555] dark:text-[#B5A8C8]">{t('campusBody')}</p>
            <dl className="mt-5 grid grid-cols-2 gap-4 border-t border-[#F4EFFA] pt-5 text-sm dark:border-[#3A2A55]">
              <div>
                <dt className="text-xs uppercase tracking-wider text-[#666] dark:text-[#B5A8C8]">{t('promoLabel')}</dt>
                <dd className="mt-1 font-heading font-bold text-[#4A2E67] dark:text-[#B084E8]">P14 / 2026</dd>
              </div>
              <div>
                <dt className="text-xs uppercase tracking-wider text-[#666] dark:text-[#B5A8C8]">{t('campusCity')}</dt>
                <dd className="mt-1 inline-flex items-center gap-1 font-heading font-bold text-[#4A2E67] dark:text-[#B084E8]">
                  <MapPin size={12} aria-hidden="true" />
                  Yaoundé, CM
                </dd>
              </div>
            </dl>
          </div>
        </div>
      </div>
    </section>
  );
}
