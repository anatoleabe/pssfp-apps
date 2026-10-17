import { apiGet, API_BASE_URL } from './client';
import type { ApiResult, CatalogueFilters, DocumentDetail, DocumentSummary, LibraryStats, MotCle, Paginated, SearchResponse } from './types';
import { filtersToSearchParams } from '../routes';

const ISR = 300;

export function getStats(): Promise<ApiResult<{ data: LibraryStats }>> {
  return apiGet('/library/stats', { revalidate: ISR });
}

export function listDocuments(filters: CatalogueFilters, page = 1, perPage?: number): Promise<ApiResult<Paginated<DocumentSummary>>> {
  const qs = filtersToSearchParams(filters);
  if (page > 1) qs.set('page', String(page));
  if (perPage) qs.set('per_page', String(perPage));
  const s = qs.toString();
  return apiGet(`/library/documents${s ? `?${s}` : ''}`, { revalidate: ISR });
}

export function getDocument(slug: string): Promise<ApiResult<{ data: DocumentDetail; related: DocumentSummary[] }>> {
  return apiGet(`/library/documents/${encodeURIComponent(slug)}`, { revalidate: ISR });
}

export function getMotsCles(): Promise<ApiResult<{ data: MotCle[] }>> {
  return apiGet('/library/mots-cles', { revalidate: 3600 });
}

export function resolveLegacyPath(path: string): Promise<ApiResult<{ data: { to_path: string; status_code: number } }>> {
  return apiGet(`/library/redirects?path=${encodeURIComponent(path)}`, { revalidate: 3600, timeoutMs: 2000 });
}

/** Navigateur uniquement : la recherche part du client (débit par IP, journaux justes). */
export function searchDocuments(q: string, filters: CatalogueFilters, page: number, signal?: AbortSignal): Promise<ApiResult<SearchResponse>> {
  const qs = filtersToSearchParams({ ...filters, tri: undefined });
  qs.set('q', q);
  if (page > 1) qs.set('page', String(page));
  return apiGet(`/library/search?${qs.toString()}`, { signal, timeoutMs: 8000 });
}

/** Navigateur uniquement : compte une consultation (sans bloquer la page). */
export function recordView(uuid: string): void {
  const url = `${API_BASE_URL}/library/documents/${encodeURIComponent(uuid)}/view`;
  if (typeof navigator !== 'undefined' && 'sendBeacon' in navigator && navigator.sendBeacon(url)) return;
  void fetch(url, { method: 'POST', keepalive: true }).catch(() => undefined);
}
