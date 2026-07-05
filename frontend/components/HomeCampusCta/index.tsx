import Image from 'next/image';
import Link from 'next/link';
import { ArrowRight, MapPin } from 'lucide-react';
import { getTranslations } from 'next-intl/server';

export async function HomeCampusCta(): Promise<JSX.Element> {
  const t = await getTranslations('home.campusCta');

  return (
    <section
      aria-labelledby="campus-cta-heading"
      data-testid="home-campus-cta"
      className="relative isolate overflow-hidden bg-pssfp-prune-dark text-white"
    >
      <Image
        src="/photos/home/campus-messa.webp"
        alt={t('imageAlt')}
        fill
        sizes="100vw"
        className="object-cover object-center"
      />
      <div
        aria-hidden="true"
        className="absolute inset-0 bg-gradient-to-r from-pssfp-prune-dark via-pssfp-prune-dark/90 to-pssfp-bleu-petrole-dark/45"
      />

      <div className="relative mx-auto max-w-7xl px-6 py-16 md:px-10 md:py-20">
        <div className="max-w-2xl">
          <p className="flex items-center gap-2 font-ui text-xs font-semibold uppercase tracking-[0.18em] text-pssfp-or">
            <MapPin size={16} aria-hidden="true" />
            {t('eyebrow')}
          </p>
          <h2
            id="campus-cta-heading"
            className="mt-4 font-heading text-4xl font-bold leading-tight text-white md:text-5xl"
          >
            {t('title')}
          </h2>
          <p className="mt-4 max-w-xl text-lg leading-relaxed text-white/90">{t('body')}</p>
          <div className="mt-7 flex flex-wrap gap-3">
            <a
              href={process.env.NEXT_PUBLIC_CANDIDATURE_URL ?? '#'}
              className="group inline-flex min-h-12 cursor-pointer items-center gap-2 rounded-pssfp-button bg-pssfp-or px-5 py-3 font-semibold text-pssfp-prune-dark transition-all duration-200 ease-out hover:bg-pssfp-or-light focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-or-light focus-visible:ring-offset-2 focus-visible:ring-offset-pssfp-prune-dark"
            >
              {t('ctaPrimary')}
              <ArrowRight
                size={17}
                aria-hidden="true"
                className="transition-transform duration-200 group-hover:translate-x-1"
              />
            </a>
            <Link
              href="/a-propos/infrastructure"
              className="inline-flex min-h-12 cursor-pointer items-center rounded-pssfp-button border border-white/60 bg-black/20 px-5 py-3 font-semibold text-white transition-all duration-200 ease-out hover:bg-black/35 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-pssfp-prune-dark"
            >
              {t('ctaSecondary')}
            </Link>
          </div>
        </div>
      </div>
    </section>
  );
}
