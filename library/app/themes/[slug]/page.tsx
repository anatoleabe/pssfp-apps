import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import { cache, Suspense } from 'react';
import { getFormatter, getTranslations } from 'next-intl/server';
import { Pagination } from '@/components/catalogue/Pagination';
import { ResultsFocus } from '@/components/catalogue/ResultsFocus';
import { StateMessage } from '@/components/catalogue/StateMessage';
import { DocumentCard } from '@/components/document/DocumentCard';
import { SearchForm } from '@/components/home/SearchForm';
import { PageHeader } from '@/components/layout/PageHeader';
import { getMotsCles, listDocuments } from '@/lib/api/library';
import type { MotCle } from '@/lib/api/types';
import { unwrapOrThrow } from '@/lib/api/unwrap';
import { COLLECTION_PER_PAGE, MAX_COLLECTION_PAGE, parsePage } from '@/lib/collections';
import { themeHref, themesHref } from '@/lib/routes';
import { childrenOf } from '@/lib/themes';

type SearchParams = Record<string, string | string[] | undefined>;

interface ThemePageProps {
  params: { slug: string };
  searchParams: SearchParams;
}

/** Slug au format du thésaurus, sinon 404 sans appel à l'API ; page au-delà du plafond : 404. */
function readRequest({ params, searchParams }: ThemePageProps): { slug: string; page: number } {
  if (!/^[a-z0-9-]{1,120}$/.test(params.slug)) notFound();
  const page = parsePage(searchParams.page);
  if (page > MAX_COLLECTION_PAGE) notFound();
  return { slug: params.slug, page };
}

/** Thésaurus (cache de données 1 h) et mot-clé demandé : slug inconnu → 404, API en panne → exception. */
const loadTheme = cache(async (slug: string): Promise<{ theme: MotCle; motsCles: MotCle[] }> => {
  const motsCles = unwrapOrThrow(await getMotsCles()).data;
  const theme = motsCles.find((m) => m.slug === slug);
  if (!theme) notFound();
  return { theme, motsCles };
});

export async function generateMetadata(props: ThemePageProps): Promise<Metadata> {
  const { slug, page } = readRequest(props);
  const { theme } = await loadTheme(slug);
  const t = await getTranslations('themes');
  const path = themeHref(slug);
  return {
    title: theme.label,
    description: t('metaDescriptionTheme', { label: theme.label }),
    alternates: { canonical: path },
    // Pages suivantes : non indexées, liens suivis (la canonique reste la première page).
    robots: page === 1 ? { index: true, follow: true } : { index: false, follow: true },
    openGraph: { title: theme.label, url: path, type: 'website', locale: 'fr_FR' },
  };
}

const RESULTS_GRID = 'grid gap-3 sm:gap-4 md:grid-cols-2';
const PRIMARY =
  'inline-flex h-10 items-center rounded-pssfp-button bg-pssfp-prune px-4 font-ui text-sm font-medium text-white transition-colors hover:bg-pssfp-prune-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2';

/**
 * Page d'un thème (rendue à la demande : `searchParams`) : documents rattachés au mot-clé ou à ses
 * descendants (`mot_cle`, API du plan 4), 12 par page, sous-thèmes en puces.
 */
export default async function ThemePage(props: ThemePageProps): Promise<JSX.Element> {
  const { slug, page } = readRequest(props);
  const [{ theme, motsCles }, listResult] = await Promise.all([loadTheme(slug), listDocuments({ mot_cle: slug }, page, COLLECTION_PER_PAGE)]);
  const { data: docs, meta } = unwrapOrThrow(listResult);

  const t = await getTranslations('themes');
  const tCollection = await getTranslations('collection');
  const tCrumbs = await getTranslations('breadcrumbs');
  const format = await getFormatter();

  const parent = theme.parent_slug ? motsCles.find((m) => m.slug === theme.parent_slug) : undefined;
  const subthemes = childrenOf(motsCles, slug);
  const outOfRange = page > 1 && page > meta.last_page;

  return (
    <>
      <PageHeader
        titleId="theme-title"
        testId="theme-header"
        title={theme.label}
        eyebrow={parent?.label ?? t('eyebrowTheme')}
        breadcrumbs={[{ href: '/', label: tCrumbs('home') }, { href: themesHref, label: tCrumbs('themes') }, { label: theme.label }]}
        meta={
          <p data-testid="theme-total" className="flex items-baseline gap-2">
            <span className="font-heading text-4xl font-semibold leading-none text-pssfp-or-light [font-variant-numeric:lining-nums_tabular-nums]">{format.number(meta.total)}</span>
            <span className="font-ui text-sm text-white/80">{tCollection('totalUnit', { count: meta.total })}</span>
          </p>
        }
        aside={<SearchForm size="compact" id="theme-search-q" filters={{ mot_cle: slug }} placeholder={t('searchPlaceholder')} tone="dark" />}
      />

      <div className="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:py-14">
        {subthemes.length > 0 && (
          <nav aria-labelledby="subthemes-title" data-testid="theme-subthemes" className="mb-10">
            <h2 id="subthemes-title" className="font-ui text-xs font-semibold uppercase tracking-[0.16em] text-pssfp-prune-light">
              {t('subthemes')}
            </h2>
            <ul className="mt-3 flex flex-wrap gap-2">
              {subthemes.map((m) => (
                <li key={m.slug}>
                  <Link
                    href={themeHref(m.slug)}
                    className="inline-flex min-h-9 items-center gap-2 rounded-full border border-pssfp-prune/50 bg-white px-3.5 font-ui text-sm text-pssfp-prune-dark transition-colors hover:border-pssfp-prune hover:bg-pssfp-mist focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2"
                  >
                    {m.label}
                    {m.documents_count !== undefined && <span className="text-xs text-pssfp-graphite-light">{format.number(m.documents_count)}</span>}
                  </Link>
                </li>
              ))}
            </ul>
          </nav>
        )}

        <section aria-labelledby="results-title" className="min-w-0">
          <h2 id="results-title" tabIndex={-1} className="sr-only">
            {t('resultsTitle', { label: theme.label })}
          </h2>
          <Suspense fallback={null}>
            <ResultsFocus targetId="results-title" />
          </Suspense>
          <div className="flex flex-wrap items-center justify-between gap-4 border-b border-pssfp-lavande/30 pb-4">
            <p role="status" data-testid="results-count" className="font-ui text-sm text-pssfp-graphite-light">
              {t('count', { count: meta.total })}
            </p>
            <Link href={themesHref} className="rounded-sm font-ui text-sm font-semibold text-pssfp-prune underline decoration-pssfp-lavande underline-offset-4 transition-colors hover:decoration-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2">
              {t('allThemes')}
            </Link>
          </div>

          <div className="mt-6">
            {outOfRange ? (
              <StateMessage
                kind="empty"
                live={false}
                testId="state-page-out-of-range"
                title={tCollection('pageOutOfRangeTitle')}
                body={tCollection('pageOutOfRangeBody', { last: meta.last_page })}
                action={
                  <Link href={themeHref(slug)} scroll={false} className={PRIMARY}>
                    {tCollection('firstPage')}
                  </Link>
                }
              />
            ) : docs.length === 0 ? (
              <StateMessage
                kind="empty"
                live={false}
                title={t('emptyThemeTitle')}
                body={t('emptyThemeBody')}
                action={
                  <Link href={themesHref} className={PRIMARY}>
                    {t('allThemes')}
                  </Link>
                }
              />
            ) : (
              <ul data-testid="theme-results" className={RESULTS_GRID}>
                {docs.map((doc) => (
                  <li key={doc.uuid} className="flex [&>article]:w-full">
                    <DocumentCard doc={doc} layout="row" />
                  </li>
                ))}
              </ul>
            )}
          </div>

          {!outOfRange && <Pagination page={meta.current_page} lastPage={meta.last_page} hrefFor={(p) => themeHref(slug, p)} scroll={false} />}
        </section>
      </div>
    </>
  );
}
