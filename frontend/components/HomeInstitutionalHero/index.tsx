import Image from 'next/image';
import Link from 'next/link';
import { ArrowRight, BadgeCheck, GraduationCap, MapPin } from 'lucide-react';
import { getTranslations } from 'next-intl/server';
import { mediaUrl } from '@/lib/media';

export async function HomeInstitutionalHero(): Promise<JSX.Element> {
  const t = await getTranslations('home.institutionalHero');

  return (
    <section
      aria-labelledby="institutional-hero-heading"
      data-testid="home-institutional-hero"
      className="relative isolate min-h-[620px] overflow-hidden bg-pssfp-bleu-petrole-dark text-white md:min-h-[680px]"
    >
      <Image
        src={mediaUrl('photos/slidershow/slidershow1-pssfp1.webp')}
        alt={t('imageAlt')}
        fill
        priority
        fetchPriority="high"
        sizes="100vw"
        quality={85}
        className="object-cover object-center"
      />
      <div
        aria-hidden="true"
        className="absolute inset-0 bg-gradient-to-r from-black/90 via-pssfp-bleu-petrole-dark/75 to-black/15"
      />
      <div
        aria-hidden="true"
        className="absolute inset-x-0 bottom-0 h-56 bg-gradient-to-t from-black/75 to-transparent"
      />

      <div className="relative mx-auto flex min-h-[620px] max-w-7xl items-center px-6 py-16 md:min-h-[680px] md:px-10">
        <div className="max-w-3xl">
          <div className="flex flex-wrap items-center gap-3">
            <p className="font-ui text-xs font-semibold uppercase tracking-[0.18em] text-pssfp-or">
              {t('eyebrow')}
            </p>
            <span className="inline-flex items-center gap-2 rounded-full border border-pssfp-or/50 bg-black/25 px-3 py-1 font-ui text-xs font-semibold text-pssfp-or-light backdrop-blur-sm">
              <span className="h-2 w-2 rounded-full bg-success" aria-hidden="true" />
              {t('campaign')}
            </span>
          </div>

          <h1
            id="institutional-hero-heading"
            className="mt-6 max-w-3xl font-heading text-[clamp(3rem,1.5rem+5vw,5.75rem)] font-bold leading-[0.98] text-white"
          >
            {t('title')}
          </h1>
          <p className="mt-6 max-w-2xl text-lg leading-relaxed text-white/90 md:text-xl">
            {t('subtitle')}
          </p>

          <div className="mt-8 flex flex-wrap gap-3">
            <a
              href={process.env.NEXT_PUBLIC_CANDIDATURE_URL ?? '#'}
              data-testid="hero-cta-candidature"
              className="group inline-flex min-h-14 cursor-pointer items-center gap-2 rounded-pssfp-button bg-pssfp-or px-6 py-3 text-base font-semibold text-pssfp-prune-dark shadow-pssfp-glow-or transition-all duration-200 ease-out hover:-translate-y-0.5 hover:bg-pssfp-or-light hover:shadow-pssfp-floating focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-or-light focus-visible:ring-offset-2 focus-visible:ring-offset-pssfp-bleu-petrole-dark"
            >
              {t('ctaPrimary')}
              <ArrowRight
                size={18}
                aria-hidden="true"
                className="transition-transform duration-200 group-hover:translate-x-1"
              />
            </a>
            <Link
              href="/formations"
              data-testid="hero-cta-formations"
              className="inline-flex min-h-14 cursor-pointer items-center rounded-pssfp-button border border-white/65 bg-white/10 px-6 py-3 text-base font-semibold text-white backdrop-blur-sm transition-all duration-200 ease-out hover:-translate-y-0.5 hover:bg-white/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-pssfp-bleu-petrole-dark"
            >
              {t('ctaSecondary')}
            </Link>
          </div>

          <p className="mt-5 font-ui text-sm font-medium text-white/80">{t('deadline')}</p>

          <ul className="mt-10 grid max-w-3xl gap-3 border-t border-white/25 pt-5 text-sm text-white/90 sm:grid-cols-3">
            <li className="flex items-center gap-2">
              <BadgeCheck size={18} className="shrink-0 text-pssfp-or" aria-hidden="true" />
              {t('trustCames')}
            </li>
            <li className="flex items-center gap-2">
              <GraduationCap size={18} className="shrink-0 text-pssfp-or" aria-hidden="true" />
              {t('trustPromotions')}
            </li>
            <li className="flex items-center gap-2">
              <MapPin size={18} className="shrink-0 text-pssfp-or" aria-hidden="true" />
              {t('trustCampus')}
            </li>
          </ul>
        </div>
      </div>
    </section>
  );
}
