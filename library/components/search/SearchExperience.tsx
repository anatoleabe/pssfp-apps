'use client';

import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { useEffect, useRef, useState } from 'react';
import { ChevronDown, RotateCw, SlidersHorizontal } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { Pagination } from '@/components/catalogue/Pagination';
import { StateMessage } from '@/components/catalogue/StateMessage';
import { DocumentCard } from '@/components/document/DocumentCard';
import { searchDocuments } from '@/lib/api/library';
import type { CatalogueFilters, SearchResponse } from '@/lib/api/types';
import { activeFilterKeys } from '@/lib/collections';
import { MAX_SEARCH_PAGE, readSearchRequest, searchPageHref, searchRequestKey, searchResetHref, type SearchRequest } from '@/lib/search';
import { FacetList } from './FacetList';
import { CollectionLinks, FacetSkeleton, SearchInvite, SearchSkeleton } from './SearchStates';

type View = { status: 'loading' } | { status: 'error'; throttled: boolean } | { status: 'success'; data: SearchResponse };

/** Réponse reçue, rattachée à la recherche (clé + tentative) qui l'a produite. */
interface Settled {
  key: string;
  attempt: number;
  view: View;
}

const LOADING: View = { status: 'loading' };
const FOCUS = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-or focus-visible:ring-offset-2';
const TEXT_LINK = `rounded-sm font-ui text-sm font-medium text-pssfp-prune underline decoration-pssfp-lavande underline-offset-4 transition-colors hover:decoration-pssfp-prune ${FOCUS}`;
const PRIMARY = `inline-flex h-10 items-center gap-2 rounded-pssfp-button bg-pssfp-prune px-4 font-ui text-sm font-medium text-white transition-colors hover:bg-pssfp-prune-dark active:bg-pssfp-prune-dark ${FOCUS}`;
const RESULTS_GRID = 'grid gap-3 sm:gap-4 md:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2';

/** Nombre de filtres appliqués, type compris (sur /recherche, le type est un filtre comme un autre). */
const countFilters = (filters: CatalogueFilters): number => activeFilterKeys(filters).length + (filters.type ? 1 : 0);

/**
 * Interroge l'API depuis le navigateur à chaque changement de recherche (la précédente est annulée).
 * Débit par IP et journaux de recherche restent ainsi ceux du visiteur, pas du serveur Next.
 */
function useSearch(key: string | null): { view: View; retry: () => void } {
  const [attempt, setAttempt] = useState(0);
  const [settled, setSettled] = useState<Settled | null>(null);

  useEffect(() => {
    const request = key === null ? null : readSearchRequest(new URLSearchParams(key));
    if (key === null || request === null) return;
    const controller = new AbortController();
    const settle = (view: View): void => {
      if (!controller.signal.aborted) setSettled({ key, attempt, view });
    };
    searchDocuments(request.q, request.filters, request.page, controller.signal)
      .then((r) => settle(r.ok ? { status: 'success', data: r.data } : { status: 'error', throttled: r.error.status === 429 }))
      .catch(() => settle({ status: 'error', throttled: false }));
    return () => controller.abort();
  }, [key, attempt]);

  // Tant que la réponse de la recherche courante n'est pas arrivée : chargement (jamais d'ancienne réponse).
  const view = settled !== null && settled.key === key && settled.attempt === attempt ? settled.view : LOADING;
  return { view, retry: () => setAttempt((n) => n + 1) };
}

export function SearchExperience(): JSX.Element {
  const t = useTranslations('search');
  const tStates = useTranslations('states');
  const tCatalogue = useTranslations('catalogue');
  const searchParams = useSearchParams();
  const key = searchRequestKey(searchParams);
  const request = key === null ? null : readSearchRequest(new URLSearchParams(key));
  const { view, retry } = useSearch(key);
  const resultsRef = useRef<HTMLDivElement>(null);

  const data = view.status === 'success' ? view.data : null;
  const activeCount = request ? countFilters(request.filters) : 0;
  const withFacets = request !== null && view.status !== 'error';
  const hasFacets = data !== null && (Object.keys(data.meta.facets.type ?? {}).length > 0 || Object.keys(data.meta.facets.access_mode ?? {}).length > 0 || activeCount > 0);

  // Une seule région vivante, toujours montée : elle annonce le chargement puis le nombre de résultats.
  let status = '';
  if (request && view.status === 'loading') status = t('loading');
  if (request && data) status = t('resultsCount', { count: data.meta.total, q: request.q });

  const onRetry = (): void => {
    retry();
    // Le bouton disparaît au profit du squelette : le focus reste dans la région des résultats.
    resultsRef.current?.focus();
  };

  return (
    <div className={`mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:py-14 ${withFacets ? 'grid gap-8 lg:grid-cols-[17rem_minmax(0,1fr)] lg:gap-12' : ''}`}>
      {withFacets ? (
        <aside aria-labelledby="facets-title" data-testid="facets-desktop" className="hidden lg:block">
          <div className="rounded-pssfp-card border border-pssfp-lavande/30 bg-white p-6 shadow-pssfp-soft">
            <h2 id="facets-title" className="font-heading text-2xl font-semibold text-pssfp-prune">
              {t('facets.title')}
            </h2>
            <div className="mt-5">{data && request ? <FacetList q={request.q} filters={request.filters} facets={data.meta.facets} /> : <FacetSkeleton />}</div>
          </div>
        </aside>
      ) : null}

      {withFacets && data && request && hasFacets ? (
        <details data-testid="facets-mobile" className="group rounded-pssfp-card border border-pssfp-lavande/40 bg-white shadow-pssfp-soft lg:hidden">
          <summary className={`flex cursor-pointer list-none items-center gap-3 rounded-pssfp-card px-4 py-3 font-ui text-sm font-medium text-pssfp-prune [&::-webkit-details-marker]:hidden ${FOCUS}`}>
            <SlidersHorizontal size={16} aria-hidden="true" />
            <span>{t('facets.toggle')}</span>
            {activeCount > 0 && <span className="rounded-full bg-pssfp-prune px-2 py-0.5 text-xs text-white">{t('facets.activeCount', { count: activeCount })}</span>}
            <ChevronDown size={16} aria-hidden="true" className="ml-auto transition-transform group-open:rotate-180 motion-reduce:transition-none" />
          </summary>
          <div className="border-t border-pssfp-lavande/30 px-4 pb-5 pt-5">
            <FacetList q={request.q} filters={request.filters} facets={data.meta.facets} />
          </div>
        </details>
      ) : null}

      <section aria-labelledby="results-title" className="min-w-0">
        <h2 id="results-title" className="sr-only">
          {t('resultsTitle')}
        </h2>
        <div className={request ? 'flex flex-col gap-3 border-b border-pssfp-lavande/30 pb-4 sm:flex-row sm:items-center sm:justify-between' : ''}>
          <p role="status" aria-live="polite" data-testid="results-count" className={request && view.status !== 'error' ? 'font-ui text-sm text-pssfp-graphite-light' : 'sr-only'}>
            {status}
          </p>
          {request && activeCount > 0 && view.status === 'success' && (
            <Link href={searchResetHref(request.q)} className={TEXT_LINK}>
              {tCatalogue('resetFilters')}
            </Link>
          )}
        </div>

        <div ref={resultsRef} tabIndex={-1} id="search-results" data-testid="search-results" aria-busy={request !== null && view.status === 'loading'} className={`focus:outline-none ${request ? 'mt-6' : ''}`}>
          {request === null ? <SearchInvite /> : <Results request={request} view={view} activeCount={activeCount} onRetry={onRetry} retryLabel={tStates('retry')} />}
        </div>

        {request && data && data.data.length > 0 && (
          <Pagination page={request.page} lastPage={Math.min(data.meta.last_page, MAX_SEARCH_PAGE)} hrefFor={(p) => searchPageHref(request.q, request.filters, p)} />
        )}
      </section>
    </div>
  );
}

interface ResultsProps {
  request: SearchRequest;
  view: View;
  activeCount: number;
  onRetry: () => void;
  retryLabel: string;
}

function Results({ request, view, activeCount, onRetry, retryLabel }: ResultsProps): JSX.Element {
  const t = useTranslations('search');
  const tCatalogue = useTranslations('catalogue');

  if (view.status === 'loading') return <SearchSkeleton />;

  if (view.status === 'error') {
    return (
      <StateMessage
        kind="error"
        title={t('errorTitle')}
        body={view.throttled ? t('throttledBody') : t('errorBody')}
        action={
          <button type="button" onClick={onRetry} className={PRIMARY}>
            <RotateCw size={16} aria-hidden="true" />
            {retryLabel}
          </button>
        }
      />
    );
  }

  const { data: docs, highlights } = view.data;
  if (docs.length === 0) {
    const pastEnd = request.page > 1 && view.data.meta.total > 0;
    return (
      <StateMessage
        kind="empty"
        live={false}
        title={t('emptyTitle', { q: request.q })}
        body={pastEnd ? t('emptyPageBody') : activeCount > 0 ? t('emptyFiltersBody') : t('emptyBody')}
        action={
          <div className="flex flex-col items-start gap-5">
            {(pastEnd || activeCount > 0) && (
              <Link href={pastEnd ? searchPageHref(request.q, request.filters) : searchResetHref(request.q)} className={PRIMARY}>
                {pastEnd ? t('firstPage') : tCatalogue('resetFilters')}
              </Link>
            )}
            <div>
              <p className="mb-3 font-ui text-sm text-pssfp-graphite-light">{t('inviteCollections')}</p>
              <CollectionLinks />
            </div>
          </div>
        }
      />
    );
  }

  return (
    <ul data-testid="search-results-list" className={RESULTS_GRID}>
      {docs.map((doc) => (
        <li key={doc.uuid} className="flex [&>article]:w-full">
          <DocumentCard doc={doc} layout="row" highlight={highlights[doc.uuid]} />
        </li>
      ))}
    </ul>
  );
}
