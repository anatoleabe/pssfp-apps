import type { Metadata } from 'next';
import Link from 'next/link';
import { ArrowLeft, Tags } from 'lucide-react';
import { getTranslations } from 'next-intl/server';
import { Eyebrow } from '@/components/home/Eyebrow';
import { SearchForm } from '@/components/home/SearchForm';
import { BandDecor } from '@/components/layout/PageHeader';
import { themesHref } from '@/lib/routes';

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations('notFound');
  return { title: t('metaTitle'), robots: { index: false, follow: true } };
}

const PRIMARY =
  'inline-flex h-11 items-center justify-center gap-2 rounded-pssfp-button bg-pssfp-prune px-5 font-ui text-sm font-medium text-white shadow-pssfp-soft transition-colors hover:bg-pssfp-prune-dark active:bg-pssfp-prune-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2';
const SECONDARY =
  'inline-flex h-11 items-center justify-center gap-2 rounded-pssfp-button border border-pssfp-prune/30 bg-white px-5 font-ui text-sm font-medium text-pssfp-prune transition-colors hover:border-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2';

/** Page introuvable : bandeau prune de la charte, recherche, retour à l'accueil et aux thèmes. */
export default async function NotFound(): Promise<JSX.Element> {
  const t = await getTranslations('notFound');
  return (
    <>
      <section aria-labelledby="not-found-title" className="relative isolate overflow-hidden bg-pssfp-prune-deep text-white">
        <BandDecor />
        <div className="mx-auto max-w-7xl px-4 py-16 sm:px-6 md:py-24">
          <div className="max-w-3xl">
            <Eyebrow tone="light">{t('eyebrow')}</Eyebrow>
            <h1 id="not-found-title" className="mt-4 text-balance font-heading text-pssfp-h1 font-semibold">
              {t('title')}
            </h1>
            <p className="mt-5 max-w-2xl text-pssfp-lead text-white/85">{t('body')}</p>
          </div>
          <div className="mt-10 max-w-2xl">
            <SearchForm size="hero" id="not-found-search-q" tone="dark" />
          </div>
        </div>
      </section>
      <div className="mx-auto flex max-w-7xl flex-col gap-3 px-4 py-10 sm:flex-row sm:px-6">
        <Link href="/" className={PRIMARY}>
          <ArrowLeft size={16} aria-hidden="true" />
          {t('home')}
        </Link>
        <Link href={themesHref} className={SECONDARY}>
          <Tags size={16} aria-hidden="true" />
          {t('themes')}
        </Link>
      </div>
    </>
  );
}
