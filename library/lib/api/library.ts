import { apiGet, API_BASE_URL } from './client';
import type { ApiResult, CatalogueFilters, DocumentDetail, DocumentSummary, LibraryStats, MotCle, Paginated, SearchResponse } from './types';
import { filtersToSearchParams } from '../routes';

const ISR = 300;

/** Durée de cache de données propre à l'appelant (ex. sitemap : 3600 s). */
export interface CacheOptions {
  revalidate?: number;
}

/** PHP sérialise un tableau associatif vide en `[]` : on le ramène à `{}`. */
export function asRecord<V>(value: unknown): Partial<Record<string, V>> {
  return value !== null && typeof value === 'object' && !Array.isArray(value) ? (value as Partial<Record<string, V>>) : {};
}

export async function getStats(options: CacheOptions = {}): Promise<ApiResult<{ data: LibraryStats }>> {
  const r = await apiGet<{ data: LibraryStats }>('/library/stats', { revalidate: options.revalidate ?? ISR });
  if (!r.ok) return r;
  const stats = r.data.data;
  const promotions = stats.promotions_count;
  return {
    ok: true,
    data: { data: { ...stats, by_type: asRecord<number>(stats.by_type), promotions_count: typeof promotions === 'number' && promotions > 0 ? promotions : 0 } },
  };
}

export function listDocuments(filters: CatalogueFilters, page = 1, perPage?: number, options: CacheOptions = {}): Promise<ApiResult<Paginated<DocumentSummary>>> {
  const qs = filtersToSearchParams(filters);
  if (page > 1) qs.set('page', String(page));
  if (perPage) qs.set('per_page', String(perPage));
  const s = qs.toString();
  return apiGet(`/library/documents${s ? `?${s}` : ''}`, { revalidate: options.revalidate ?? ISR });
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
export async function searchDocuments(q: string, filters: CatalogueFilters, page: number, signal?: AbortSignal): Promise<ApiResult<SearchResponse>> {
  const qs = filtersToSearchParams({ ...filters, tri: undefined });
  qs.set('q', q);
  if (page > 1) qs.set('page', String(page));
  const r = await apiGet<SearchResponse>(`/library/search?${qs.toString()}`, { signal, timeoutMs: 8000 });
  if (!r.ok) return r;
  const { meta } = r.data;
  return {
    ok: true,
    data: {
      ...r.data,
      highlights: asRecord<string>(r.data.highlights) as Record<string, string>,
      meta: { ...meta, facets: { type: asRecord<number>(meta.facets?.type), access_mode: asRecord<number>(meta.facets?.access_mode) } },
    },
  };
}

/** Navigateur uniquement : compte une consultation (sans bloquer la page). */
export function recordView(uuid: string): void {
  const url = `${API_BASE_URL}/library/documents/${encodeURIComponent(uuid)}/view`;
  if (typeof navigator !== 'undefined' && 'sendBeacon' in navigator && navigator.sendBeacon(url)) return;
  void fetch(url, { method: 'POST', keepalive: true }).catch(() => undefined);
}
