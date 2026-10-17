import Link from 'next/link';
import { RotateCw } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { StateMessage } from '@/components/catalogue/StateMessage';
import { DocumentCard } from '@/components/document/DocumentCard';
import type { SearchResponse } from '@/lib/api/types';
import { searchPageHref, searchResetHref, type SearchRequest } from '@/lib/search';
import { CollectionLinks } from './SearchStates';

export const FOCUS = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2';
export const PRIMARY = `inline-flex h-10 items-center gap-2 rounded-pssfp-button bg-pssfp-prune px-4 font-ui text-sm font-medium text-white transition-colors hover:bg-pssfp-prune-dark active:bg-pssfp-prune-dark ${FOCUS}`;
const RESULTS_GRID = 'grid gap-3 sm:gap-4 md:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2';

interface SearchErrorProps {
  throttled: boolean;
  onRetry: () => void;
}

/** Échec de la requête (réseau, API, débit) : alerte et bouton « Réessayer ». */
export function SearchError({ throttled, onRetry }: SearchErrorProps): JSX.Element {
  const t = useTranslations('search');
  const tStates = useTranslations('states');
  return (
    <StateMessage
      kind="error"
      title={t('errorTitle')}
      body={throttled ? t('throttledBody') : t('errorBody')}
      action={
        <button type="button" onClick={onRetry} className={PRIMARY}>
          <RotateCw size={16} aria-hidden="true" />
          {tStates('retry')}
        </button>
      }
    />
  );
}

interface SearchResultsProps {
  request: SearchRequest;
  data: SearchResponse;
  activeCount: number;
}

/** Cartes surlignées, ou état vide utile (retrait des filtres, première page, collections). */
export function SearchResults({ request, data, activeCount }: SearchResultsProps): JSX.Element {
  const t = useTranslations('search');
  const tCatalogue = useTranslations('catalogue');
  const { data: docs, highlights } = data;

  if (docs.length === 0) {
    const pastEnd = request.page > 1 && data.meta.total > 0;
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
