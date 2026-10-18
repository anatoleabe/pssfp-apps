import type { CatalogueFilters } from './api/types';
import { filtersToSearchParams, searchParamsToFilters } from './routes';

/** Bornes de l'API de recherche (`SearchController`) : au-delà, elle répond 422. */
export const MIN_QUERY_LENGTH = 2;
export const MAX_QUERY_LENGTH = 200;
export const MAX_SEARCH_PAGE = 500;

/** Filtres activables depuis une facette. */
export type FacetKey = 'type' | 'acces';

export interface SearchRequest {
  q: string;
  /** Filtres reconnus, sans tri (la recherche est classée par pertinence). */
  filters: CatalogueFilters;
  page: number;
}

/** Lit l'URL non fiable de /recherche ; null si `q` compte moins de deux caractères. */
export function readSearchRequest(sp: URLSearchParams): SearchRequest | null {
  const q = (sp.get('q') ?? '').trim().slice(0, MAX_QUERY_LENGTH);
  if (q.length < MIN_QUERY_LENGTH) return null;
  const { tri: _sort, ...filters } = searchParamsToFilters(sp);
  const raw = sp.get('page') ?? '';
  const n = /^\d{1,6}$/.test(raw) ? Number(raw) : 1;
  return { q, filters, page: Math.min(Math.max(n, 1), MAX_SEARCH_PAGE) };
}

/** Lien vers une page de résultats : filtres conservés, `page` omise pour la première. */
export function searchPageHref(q: string, filters: CatalogueFilters, page = 1): string {
  const qs = filtersToSearchParams({ ...filters, tri: undefined });
  qs.set('q', q);
  if (page > 1) qs.set('page', String(page));
  return `/recherche?${qs.toString()}`;
}

/** Même recherche, sans aucun filtre. */
export const searchResetHref = (q: string): string => searchPageHref(q, {});

/**
 * Clé canonique d'une recherche (chaîne stable pour deux URL équivalentes, relisible par
 * `readSearchRequest`) : sert de dépendance à l'effet qui interroge l'API.
 */
export function searchRequestKey(sp: URLSearchParams): string | null {
  const req = readSearchRequest(sp);
  return req ? searchPageHref(req.q, req.filters, req.page).slice('/recherche?'.length) : null;
}

/** Facette : ajoute (ou remplace) le filtre, ou le retire s'il est déjà appliqué ; retour en page 1. */
export function facetHref(q: string, filters: CatalogueFilters, key: FacetKey, value: string): string {
  const { [key]: current, ...rest } = filters;
  return searchPageHref(q, current === value ? rest : { ...rest, [key]: value });
}
