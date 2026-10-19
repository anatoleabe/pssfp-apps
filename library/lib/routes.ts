import { ACCESS_MODES, DOCUMENT_TYPES, LANGUAGES, SORT_KEYS, type CatalogueFilters, type DocumentType } from './api/types';
import { API_BASE_URL } from './api/client';

/** Bornes acceptées pour les filtres numériques (valeurs hors bornes ignorées). */
const INT_BOUNDS = {
  promotion: [1, 99],
  annee_min: [1900, 2100],
  annee_max: [1900, 2100],
} as const satisfies Record<'promotion' | 'annee_min' | 'annee_max', readonly [number, number]>;

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
  for (const k of ['promotion', 'annee_min', 'annee_max'] as const) {
    const raw = get(k);
    const n = raw !== undefined && /^\d{1,4}$/.test(raw) ? Number(raw) : NaN;
    const [min, max] = INT_BOUNDS[k];
    if (Number.isInteger(n) && n >= min && n <= max) f[k] = n;
  }
  const langue = get('langue'); if (langue && (LANGUAGES as readonly string[]).includes(langue)) f.langue = langue;
  for (const k of ['specialite', 'mot_cle'] as const) { const v = get(k); if (v && /^[a-z0-9-]{1,120}$/.test(v)) f[k] = v; }
  return f;
}

export const collectionHref = (type: DocumentType, f: CatalogueFilters = {}): string => {
  const qs = filtersToSearchParams({ ...f, type: undefined }).toString();
  return `/collections/${type}${qs ? `?${qs}` : ''}`;
};
export const searchHref = (q: string, f: CatalogueFilters = {}): string => {
  const qs = filtersToSearchParams(f); qs.set('q', q); return `/recherche?${qs.toString()}`;
};
export const themesHref = '/themes';
export const themeHref = (slug: string, page = 1): string => `/themes/${encodeURIComponent(slug)}${page > 1 ? `?page=${page}` : ''}`;
export const documentHref = (slug: string): string => `/documents/${encodeURIComponent(slug)}`;
export const downloadHref = (uuid: string): string => `${API_BASE_URL}/library/documents/${encodeURIComponent(uuid)}/download`;
