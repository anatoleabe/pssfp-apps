'use client';

import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { useEffect, useRef, useState, type RefObject } from 'react';
import { ChevronDown, SlidersHorizontal } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { Pagination } from '@/components/catalogue/Pagination';
import { searchDocuments } from '@/lib/api/library';
import type { CatalogueFilters, SearchResponse } from '@/lib/api/types';
import { activeFilterKeys } from '@/lib/collections';
import { MAX_SEARCH_PAGE, readSearchRequest, searchPageHref, searchRequestKey, searchResetHref, type SearchRequest } from '@/lib/search';
import { FacetList, hasFacetValues } from './FacetList';
import { FOCUS, SearchError, SearchResults } from './SearchResults';
import { SearchInvite, SearchSkeleton } from './SearchStates';

type View = { status: 'loading' } | { status: 'error'; throttled: boolean } | { status: 'success'; data: SearchResponse };

/** Réponse reçue, rattachée à la recherche (clé + tentative) qui l'a produite. */
interface Settled {
  key: string;
  attempt: number;
  view: View;
}

/** Dernier jeu de résultats affichable, avec la recherche qui l'a produit. */
interface Shown {
  request: SearchRequest;
  data: SearchResponse;
}

const LOADING: View = { status: 'loading' };
const TEXT_LINK = `rounded-sm font-ui text-sm font-medium text-pssfp-prune underline decoration-pssfp-lavande underline-offset-4 transition-colors hover:decoration-pssfp-prune ${FOCUS}`;

/** Nombre de filtres appliqués, type compris (sur /recherche, le type est un filtre comme un autre). */
const countFilters = (filters: CatalogueFilters): number => activeFilterKeys(filters).length + (filters.type ? 1 : 0);

/**
 * Interroge l'API depuis le navigateur à chaque changement de recherche (la précédente est annulée :
 * une réponse tardive ne peut jamais remplacer celle de la recherche courante).
 * Débit par IP et journaux de recherche restent ainsi ceux du visiteur, pas du serveur Next.
 */
function useSearch(key: string | null): { view: View; lastGood: Shown | null; retry: () => void } {
  const [attempt, setAttempt] = useState(0);
  const [settled, setSettled] = useState<Settled | null>(null);
  const [lastGood, setLastGood] = useState<Shown | null>(null);

  useEffect(() => {
    const request = key === null ? null : readSearchRequest(new URLSearchParams(key));
    if (key === null || request === null) return;
    const controller = new AbortController();
    const settle = (view: View): void => {
      if (controller.signal.aborted) return;
      setSettled({ key, attempt, view });
      if (view.status === 'success') setLastGood({ request, data: view.data });
    };
    searchDocuments(request.q, request.filters, request.page, controller.signal)
      .then((r) => settle(r.ok ? { status: 'success', data: r.data } : { status: 'error', throttled: r.error.status === 429 }))
      .catch(() => settle({ status: 'error', throttled: false }));
    return () => controller.abort();
  }, [key, attempt]);

  const view = settled !== null && settled.key === key && settled.attempt === attempt ? settled.view : LOADING;
  return { view, lastGood, retry: () => setAttempt((n) => n + 1) };
}

/**
 * Après une navigation dans la page (facette, pagination, Retour), le focus reste sur l'élément
 * activé s'il est toujours monté ; sinon il passe au titre « Résultats » (jamais perdu sur `body`).
 */
function useFocusFallback(key: string | null, view: View, target: RefObject<HTMLElement>): void {
  const previousKey = useRef<string | null | undefined>(undefined);
  const navigated = useRef(false);

  useEffect(() => {
    if (previousKey.current !== undefined && previousKey.current !== key) navigated.current = true;
    previousKey.current = key;
  }, [key]);

  useEffect(() => {
    if (view.status === 'loading' || !navigated.current) return;
    navigated.current = false;
    const active = document.activeElement;
    if (active === null || active === document.body) target.current?.focus();
  }, [view, target]);
}

export function SearchExperience(): JSX.Element {
  const t = useTranslations('search');
  const tCatalogue = useTranslations('catalogue');
  const searchParams = useSearchParams();
  const key = searchRequestKey(searchParams);
  const request = key === null ? null : readSearchRequest(new URLSearchParams(key));
  const { view, lastGood, retry } = useSearch(key);
  const headingRef = useRef<HTMLHeadingElement>(null);
  useFocusFallback(key, view, headingRef);

  const busy = request !== null && view.status === 'loading';
  // Pendant une nouvelle requête, les derniers résultats restent montés (atténués) : pas de saut, focus conservé.
  const current: Shown | null = request && view.status === 'success' ? { request, data: view.data } : null;
  const shown = current ?? (busy ? lastGood : null);
  const stale = shown !== null && current === null;
  const withFacets = shown !== null && hasFacetValues(shown.data.meta.facets, shown.request.filters);
  const activeCount = request ? countFilters(request.filters) : 0;
  const shownCount = shown ? countFilters(shown.request.filters) : 0;

  // Une seule région vivante, toujours montée : « Recherche en cours… », puis le nombre de résultats.
  let status = '';
  if (busy) status = t('loading');
  if (current) {
    const { total, last_page: lastPage } = current.data.meta;
    const values = { count: total, q: current.request.q, page: current.request.page, last: Math.min(lastPage, MAX_SEARCH_PAGE) };
    status = current.request.page > 1 ? t('resultsCountPage', values) : t('resultsCount', values);
  }

  const onRetry = (): void => {
    retry();
    // Le bouton disparaît au profit du chargement : le focus passe au titre des résultats.
    headingRef.current?.focus();
  };

  return (
    <div className={`mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:py-14 ${withFacets ? 'grid gap-8 lg:grid-cols-[17rem_minmax(0,1fr)] lg:gap-12' : ''}`}>
      {withFacets && shown ? (
        <aside aria-labelledby="facets-title" data-testid="facets-desktop" className="hidden lg:block">
          <div className="rounded-pssfp-card border border-pssfp-lavande/30 bg-white p-6 shadow-pssfp-soft">
            <h2 id="facets-title" className="font-heading text-2xl font-semibold text-pssfp-prune">
              {t('facets.title')}
            </h2>
            <div className="mt-5">
              <FacetList q={shown.request.q} filters={shown.request.filters} facets={shown.data.meta.facets} idPrefix="desktop" titleAs="h3" />
            </div>
          </div>
        </aside>
      ) : null}

      {withFacets && shown ? (
        <details data-testid="facets-mobile" className="group rounded-pssfp-card border border-pssfp-lavande/40 bg-white shadow-pssfp-soft lg:hidden">
          <summary className={`flex cursor-pointer list-none items-center gap-3 rounded-pssfp-card px-4 py-3 font-ui text-sm font-medium text-pssfp-prune [&::-webkit-details-marker]:hidden ${FOCUS}`}>
            <SlidersHorizontal size={16} aria-hidden="true" />
            <span>{t('facets.toggle')}</span>
            {shownCount > 0 && <span className="rounded-full bg-pssfp-prune px-2 py-0.5 text-xs text-white">{t('facets.activeCount', { count: shownCount })}</span>}
            <ChevronDown size={16} aria-hidden="true" className="ml-auto transition-transform group-open:rotate-180 motion-reduce:transition-none" />
          </summary>
          <div className="border-t border-pssfp-lavande/30 px-4 pb-5 pt-5">
            <FacetList q={shown.request.q} filters={shown.request.filters} facets={shown.data.meta.facets} idPrefix="mobile" titleAs="p" />
          </div>
        </details>
      ) : null}

      <section aria-labelledby="results-title" className="min-w-0">
        <div className={request ? 'border-b border-pssfp-lavande/30 pb-4' : ''}>
          <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2">
            <h2
              id="results-title"
              ref={headingRef}
              tabIndex={-1}
              data-testid="results-title"
              className={request ? `rounded-sm font-heading text-2xl font-semibold text-pssfp-prune ${FOCUS}` : 'sr-only'}
            >
              {t('resultsTitle')}
            </h2>
            {request && activeCount > 0 && view.status !== 'error' && (
              <Link href={searchResetHref(request.q)} className={TEXT_LINK}>
                {tCatalogue('resetFilters')}
              </Link>
            )}
          </div>
          <p role="status" aria-live="polite" data-testid="results-count" className={request && view.status !== 'error' ? 'mt-1 font-ui text-sm text-pssfp-graphite-light' : 'sr-only'}>
            {status}
          </p>
        </div>

        <div
          id="search-results"
          data-testid="search-results"
          aria-busy={busy}
          className={`transition-opacity duration-200 motion-reduce:transition-none ${request ? 'mt-6' : ''} ${stale ? 'opacity-60' : ''}`}
        >
          {request === null ? (
            <SearchInvite />
          ) : view.status === 'error' ? (
            <SearchError throttled={view.throttled} onRetry={onRetry} />
          ) : shown ? (
            <SearchResults request={shown.request} data={shown.data} activeCount={shownCount} />
          ) : (
            <SearchSkeleton />
          )}
        </div>

        {view.status !== 'error' && shown && shown.data.data.length > 0 && (
          <Pagination page={shown.request.page} lastPage={Math.min(shown.data.meta.last_page, MAX_SEARCH_PAGE)} hrefFor={(p) => searchPageHref(shown.request.q, shown.request.filters, p)} />
        )}
      </section>
    </div>
  );
}
