import type { Metadata } from 'next';
import Link from 'next/link';
import { ArrowLeft, Search } from 'lucide-react';
import { getTranslations } from 'next-intl/server';

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations('notFound');
  return { title: t('metaTitle'), robots: { index: false, follow: true } };
}

export default async function NotFound(): Promise<JSX.Element> {
  const t = await getTranslations('notFound');
  return (
    <div className="mx-auto max-w-3xl px-4 py-20 sm:px-6 md:py-28">
      <p className="font-ui text-xs font-medium uppercase tracking-[0.18em] text-pssfp-bleu-petrole">{t('eyebrow')}</p>
      <h1 className="mt-3 font-heading text-pssfp-h1 font-bold text-pssfp-prune">{t('title')}</h1>
      <p className="mt-5 max-w-2xl text-pssfp-lead">{t('body')}</p>
      <div className="mt-10 flex flex-col gap-3 sm:flex-row">
        <Link
          href="/"
          className="inline-flex items-center justify-center gap-2 rounded-pssfp-button bg-pssfp-prune px-5 py-3 font-ui text-sm font-medium text-white shadow-pssfp-soft transition-colors hover:bg-pssfp-prune-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-or focus-visible:ring-offset-2"
        >
          <ArrowLeft size={16} aria-hidden="true" />
          {t('home')}
        </Link>
        <Link
          href="/recherche"
          className="inline-flex items-center justify-center gap-2 rounded-pssfp-button border border-pssfp-prune/30 bg-white px-5 py-3 font-ui text-sm font-medium text-pssfp-prune transition-colors hover:border-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2"
        >
          <Search size={16} aria-hidden="true" />
          {t('search')}
        </Link>
      </div>
    </div>
  );
}
