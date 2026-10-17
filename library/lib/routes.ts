import { ACCESS_MODES, DOCUMENT_TYPES, SORT_KEYS, type CatalogueFilters, type DocumentType } from './api/types';
import { API_BASE_URL } from './api/client';

const INT_KEYS = ['promotion', 'annee_min', 'annee_max'] as const;

export function filtersToSearchParams(f: CatalogueFilters): URLSearchParams {
  const qs = new URLSearchParams();
  for (const [k, v] of Object.entries(f)) if (v !== undefined && v !== null && v !== '') qs.set(k, String(v));
  return qs;
}

/** Lit des searchParams non fiables : ne garde que les valeurs reconnues. */
export function searchParamsToFilters(sp: URLSearchParams | Record<string, string | string[] | undefined>): CatalogueFilters {
  const get = (k: string): string | undefined => {
    const v = sp instanceof URLSearchParams ? sp.get(k) ?? undefined : sp[k];
    return Array.isArray(v) ? v[0] : v;
  };
  const f: CatalogueFilters = {};
  const type = get('type'); if (type && (DOCUMENT_TYPES as readonly string[]).includes(type)) f.type = type as DocumentType;
  const acces = get('acces'); if (acces && (ACCESS_MODES as readonly string[]).includes(acces)) f.acces = acces as CatalogueFilters['acces'];
  const tri = get('tri'); if (tri && (SORT_KEYS as readonly string[]).includes(tri)) f.tri = tri as CatalogueFilters['tri'];
  for (const k of INT_KEYS) { const n = Number(get(k)); if (Number.isInteger(n) && n > 0) f[k] = n; }
  for (const k of ['specialite', 'langue', 'mot_cle'] as const) { const v = get(k); if (v && /^[a-z0-9-]{1,120}$/.test(v)) f[k] = v; }
  return f;
}

export const collectionHref = (type: DocumentType, f: CatalogueFilters = {}): string => {
  const qs = filtersToSearchParams({ ...f, type: undefined }).toString();
  return `/collections/${type}${qs ? `?${qs}` : ''}`;
};
export const searchHref = (q: string, f: CatalogueFilters = {}): string => {
  const qs = filtersToSearchParams(f); qs.set('q', q); return `/recherche?${qs.toString()}`;
};
export const documentHref = (slug: string): string => `/documents/${encodeURIComponent(slug)}`;
export const downloadHref = (uuid: string): string => `${API_BASE_URL}/library/documents/${encodeURIComponent(uuid)}/download`;
