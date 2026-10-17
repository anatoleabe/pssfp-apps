import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import { Suspense } from 'react';
import { ChevronDown, SlidersHorizontal } from 'lucide-react';
import { getFormatter, getTranslations } from 'next-intl/server';
import { ActiveFilters } from '@/components/catalogue/ActiveFilters';
import { FilterPanel } from '@/components/catalogue/FilterPanel';
import { Pagination } from '@/components/catalogue/Pagination';
import { ResultsFocus } from '@/components/catalogue/ResultsFocus';
import { SortSelect } from '@/components/catalogue/SortSelect';
import { StateMessage } from '@/components/catalogue/StateMessage';
import { DocumentCard } from '@/components/document/DocumentCard';
import { Eyebrow } from '@/components/home/Eyebrow';
import { SearchForm } from '@/components/home/SearchForm';
import { Breadcrumbs } from '@/components/layout/Breadcrumbs';
import { getMotsCles, listDocuments } from '@/lib/api/library';
import type { CatalogueFilters, DocumentType } from '@/lib/api/types';
import { unwrapOrThrow } from '@/lib/api/unwrap';
import { COLLECTION_PER_PAGE, activeFilterKeys, collectionPageHref, collectionResetHref, isDocumentType, isIndexable, parsePage } from '@/lib/collections';
import { filtersToSearchParams, searchParamsToFilters } from '@/lib/routes';

export const revalidate = 300;

type SearchParams = Record<string, string | string[] | undefined>;

interface CollectionPageProps {
  params: { type: string };
  searchParams: SearchParams;
}

/** Type validé (sinon 404) et filtres reconnus ; le type vient du chemin, jamais de la requête. */
function readRequest({ params, searchParams }: CollectionPageProps): { type: DocumentType; filters: CatalogueFilters; page: number } {
  if (!isDocumentType(params.type)) notFound();
  const type = params.type;
  return { type, filters: { ...searchParamsToFilters(searchParams), type }, page: parsePage(searchParams.page) };
}

export async function generateMetadata(props: CollectionPageProps): Promise<Metadata> {
  const { type, filters, page } = readRequest(props);
  const tTypes = await getTranslations('types');
  const t = await getTranslations('collection');
  const title = tTypes(`${type}.other`);
  const path = `/collections/${type}`;
  return {
    title,
    description: t('metaDescription', { type: title }),
    alternates: { canonical: path },
    // Variantes filtrées, triées ou paginées : non indexées, liens suivis (la canonique reste la page nue).
    robots: isIndexable(filters, page) ? { index: true, follow: true } : { index: false, follow: true },
    openGraph: { title, url: path, type: 'website', locale: 'fr_FR' },
  };
}

/** Grille des cartes : une colonne, deux dès md, une seule à côté du panneau (lg), deux à nouveau en xl. */
const RESULTS_GRID = 'grid gap-3 sm:gap-4 md:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2';

/**
 * Collection filtrable (maquette B) : filtres GET à gauche (tiroir `<details>` sur mobile),
 * résultats paginés à droite. La liste des documents est indispensable : `unwrapOrThrow` lève une
 * exception en cas d'échec (l'ISR conserve alors la dernière version valide, sinon `app/error.tsx`).
 * Les mots-clés, eux, sont facultatifs (voir plus bas).
 */
export default async function CollectionPage(props: CollectionPageProps): Promise<JSX.Element> {
  const { type, filters, page } = readRequest(props);
  const [listResult, motsClesResult] = await Promise.all([listDocuments(filters, page, COLLECTION_PER_PAGE), getMotsCles()]);
  const { data: docs, meta } = unwrapOrThrow(listResult);
  // Exception assumée à la règle `unwrapOrThrow` : les mots-clés ne servent qu'au filtre facultatif
  // « Mot-clé ». Sans eux, la page reste servie sans ce champ plutôt que de basculer en erreur.
  const motsCles = motsClesResult.ok ? motsClesResult.data.data : [];

  const t = await getTranslations('collection');
  const tTypes = await getTranslations('types');
  const tCatalogue = await getTranslations('catalogue');
  const tCrumbs = await getTranslations('breadcrumbs');
  const tStates = await getTranslations('states');
  const format = await getFormatter();

  const title = tTypes(`${type}.other`);
  const activeCount = activeFilterKeys(filters).length;
  const resetHref = collectionResetHref(type, filters);
  // Le tri renvoie les filtres courants (sans type ni tri) ; la page repart de 1.
  const sortHidden = Object.fromEntries(filtersToSearchParams({ ...filters, type: undefined, tri: undefined }));

  return (
    <>
      <section aria-labelledby="collection-title" className="relative isolate overflow-hidden border-b border-pssfp-lavande/30">
        <div aria-hidden="true" className="pointer-events-none absolute -right-40 -top-48 -z-10 h-[30rem] w-[30rem] rounded-full bg-[radial-gradient(closest-side,rgba(165,146,189,0.26),transparent)]" />
        <div className="mx-auto max-w-7xl px-4 pb-10 pt-8 sm:px-6 sm:pb-12 lg:pt-10">
          <Breadcrumbs items={[{ href: '/', label: tCrumbs('home') }, { label: title }]} />
          <div className="mt-8 grid gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,26rem)] lg:items-end lg:gap-16">
            <div className="min-w-0">
              <Eyebrow tone="or">{t('eyebrow')}</Eyebrow>
              <h1 id="collection-title" className="mt-4 text-balance font-heading text-pssfp-h1 font-semibold text-pssfp-prune lg:text-[4rem] lg:leading-none">
                {title}
              </h1>
              <p data-testid="collection-total" className="mt-4 flex items-baseline gap-2 text-pssfp-graphite">
                <span className="font-heading text-3xl font-semibold text-pssfp-prune [font-variant-numeric:lining-nums_tabular-nums]">{format.number(meta.total)}</span>
                <span className="font-ui text-sm">{t('totalUnit', { count: meta.total })}</span>
              </p>
            </div>
            <SearchForm size="compact" id="collection-search-q" type={type} placeholder={t('searchPlaceholder')} />
          </div>
        </div>
      </section>

      <div className="mx-auto grid max-w-7xl gap-8 px-4 py-10 sm:px-6 lg:grid-cols-[17rem_minmax(0,1fr)] lg:gap-12 lg:py-14">
        {/* Colonne de filtres ≥ lg ; en dessous, le même formulaire dans un tiroir natif. */}
        <aside aria-labelledby="filters-title" className="hidden lg:block">
          <div className="rounded-pssfp-card border border-pssfp-lavande/30 bg-white p-6 shadow-pssfp-soft">
            <h2 id="filters-title" className="font-heading text-2xl font-semibold text-pssfp-prune">
              {tCatalogue('filtersTitle')}
            </h2>
            <div className="mt-5">
              <FilterPanel type={type} filters={filters} motsCles={motsCles} idPrefix="desktop" />
            </div>
          </div>
        </aside>

        <details data-testid="filters-mobile" className="group rounded-pssfp-card border border-pssfp-lavande/40 bg-white shadow-pssfp-soft lg:hidden">
          <summary className="flex cursor-pointer list-none items-center gap-3 rounded-pssfp-card px-4 py-3 font-ui text-sm font-medium text-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2 [&::-webkit-details-marker]:hidden">
            <SlidersHorizontal size={16} aria-hidden="true" />
            <span>{t('filterToggle')}</span>
            {activeCount > 0 && (
              <span className="rounded-full bg-pssfp-prune px-2 py-0.5 text-xs text-white">{t('filterCount', { count: activeCount })}</span>
            )}
            <ChevronDown size={16} aria-hidden="true" className="ml-auto transition-transform group-open:rotate-180 motion-reduce:transition-none" />
          </summary>
          <div className="border-t border-pssfp-lavande/30 px-4 pb-5 pt-5">
            <FilterPanel type={type} filters={filters} motsCles={motsCles} idPrefix="mobile" />
          </div>
        </details>

        <section aria-labelledby="results-title" className="min-w-0">
          {/* Cible du focus après une navigation dans la page (voir ResultsFocus). */}
          <h2 id="results-title" tabIndex={-1} className="sr-only">
            {t('resultsTitle')}
          </h2>
          <Suspense fallback={null}>
            <ResultsFocus targetId="results-title" />
          </Suspense>
          <div className="flex flex-col gap-4 border-b border-pssfp-lavande/30 pb-4 sm:flex-row sm:items-center sm:justify-between">
            {/* Seule région vivante de la page : annonce le nouveau total après un retrait de filtre ou un changement de page (navigation client). */}
            <p role="status" data-testid="results-count" className="font-ui text-sm text-pssfp-graphite-light">
              {tCatalogue('resultsCount', { count: meta.total })}
            </p>
            <SortSelect action={`/collections/${type}`} value={filters.tri} hidden={sortHidden} />
          </div>
          {activeCount > 0 && (
            <div className="mt-4">
              <ActiveFilters type={type} filters={filters} motsCles={motsCles} />
            </div>
          )}

          <div className="mt-6">
            {docs.length === 0 ? (
              <StateMessage
                kind="empty"
                live={false}
                title={activeCount > 0 || page > 1 ? tStates('emptyTitle') : t('emptyTitle')}
                body={activeCount > 0 || page > 1 ? tStates('emptyBody') : t('emptyBody')}
                action={
                  <Link
                    href={activeCount > 0 || page > 1 ? resetHref : '/'}
                    // Réinitialisation dans la page : pas de remontée, le focus passe aux résultats.
                    scroll={!(activeCount > 0 || page > 1)}
                    className="inline-flex h-10 items-center rounded-pssfp-button bg-pssfp-prune px-4 font-ui text-sm font-medium text-white transition-colors hover:bg-pssfp-prune-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2"
                  >
                    {activeCount > 0 || page > 1 ? tCatalogue('resetFilters') : t('backHome')}
                  </Link>
                }
              />
            ) : (
              <ul data-testid="collection-results" className={RESULTS_GRID}>
                {docs.map((doc) => (
                  <li key={doc.uuid} className="flex [&>article]:w-full">
                    <DocumentCard doc={doc} layout="row" />
                  </li>
                ))}
              </ul>
            )}
          </div>

          <Pagination page={meta.current_page} lastPage={meta.last_page} hrefFor={(p) => collectionPageHref(type, filters, p)} scroll={false} />
        </section>
      </div>
    </>
  );
}
