import { DOCUMENT_TYPES, type CatalogueFilters, type DocumentType } from './api/types';
import { filtersToSearchParams } from './routes';

/** Documents par page d'une collection (multiple de 2 : grille à deux colonnes). */
export const COLLECTION_PER_PAGE = 12;

/** Filtres affichables et retirables un à un (le type vient du chemin, le tri n'est pas un filtre). */
export const FILTER_KEYS = ['acces', 'annee_min', 'annee_max', 'langue', 'promotion', 'mot_cle', 'specialite'] as const;
export type FilterKey = (typeof FILTER_KEYS)[number];

/** Pages au-delà : refusées (aucune collection n'en compte autant, évite des requêtes inutiles). */
const MAX_PAGE = 9999;

export function isDocumentType(value: string): value is DocumentType {
  return (DOCUMENT_TYPES as readonly string[]).includes(value);
}

/** `?page=` non fiable → entier ≥ 1 (1 par défaut). */
export function parsePage(raw: string | string[] | undefined): number {
  const value = Array.isArray(raw) ? raw[0] : raw;
  if (value === undefined || !/^\d{1,4}$/.test(value)) return 1;
  const n = Number(value);
  return n >= 1 && n <= MAX_PAGE ? n : 1;
}

export function activeFilterKeys(filters: CatalogueFilters): FilterKey[] {
  return FILTER_KEYS.filter((key) => filters[key] !== undefined);
}

export function withoutFilter(filters: CatalogueFilters, key: FilterKey): CatalogueFilters {
  const { [key]: _removed, ...rest } = filters;
  return rest;
}

/** Lien vers une page de collection : filtres et tri conservés, `page` omise pour la première. */
export function collectionPageHref(type: DocumentType, filters: CatalogueFilters, page = 1): string {
  const qs = filtersToSearchParams({ ...filters, type: undefined });
  if (page > 1) qs.set('page', String(page));
  const s = qs.toString();
  return `/collections/${type}${s ? `?${s}` : ''}`;
}

/** Seule la première page sans filtre ni tri est indexée (les autres variantes renvoient à la canonique). */
export function isIndexable(filters: CatalogueFilters, page: number): boolean {
  return page === 1 && activeFilterKeys(filters).length === 0 && filters.tri === undefined;
}
