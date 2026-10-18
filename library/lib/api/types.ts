export type DocumentType =
  | 'memoire' | 'these' | 'ouvrage_numerique' | 'texte_loi' | 'article_scientifique'
  | 'cours' | 'acte_conference' | 'rapport_stage' | 'bulletin' | 'ressource_pedagogique' | 'rapport';
export type AccessMode = 'libre' | 'reserve' | 'payant';
export type SortKey = 'recent' | 'ancien' | 'titre' | 'populaire';
export type AccessReason = 'public' | 'reserved' | 'paid' | 'unpublished' | 'external';

export const DOCUMENT_TYPES: readonly DocumentType[] = ['memoire', 'these', 'ouvrage_numerique', 'texte_loi', 'article_scientifique', 'cours', 'acte_conference', 'rapport_stage', 'bulletin', 'ressource_pedagogique', 'rapport'];
export const SORT_KEYS: readonly SortKey[] = ['recent', 'ancien', 'titre', 'populaire'];
export const ACCESS_MODES: readonly AccessMode[] = ['libre', 'reserve', 'payant'];
/** Langues acceptées par l'API (Document::LANGUAGES) ; toute autre valeur y provoque une 422. */
export const LANGUAGES = ['fr', 'en', 'ar', 'autre'] as const;
export type Language = (typeof LANGUAGES)[number];

export interface MotCleRef { slug: string; label: string }

export interface DocumentSummary {
  uuid: string; slug: string; type: DocumentType; type_label: string;
  title: string; subtitle: string | null; year: number | null; language: string;
  specialite: string | null; promotion: number | null;
  authors: string[]; mots_cles: MotCleRef[]; cover_url: string | null;
  access_mode: AccessMode; price_xaf: number | null; published_at: string | null;
}

export interface AccessDecision { allowed: boolean; reason: AccessReason }

export interface DocumentDetail extends DocumentSummary {
  abstract: string | null; journal: string | null; doi: string | null; isbn: string | null;
  pages_count: number | null; file_size_bytes: number | null; external_url: string | null;
  views_count: number; access: AccessDecision;
}

export interface PageMeta { current_page: number; last_page: number; per_page: number; total: number }
export interface Paginated<T> { data: T[]; meta: PageMeta }
export interface LibraryStats {
  total: number; by_type: Partial<Record<DocumentType, number>>; paid_count: number;
  /** Promotions distinctes parmi les mémoires publiés (absent des anciennes API : 0). */
  promotions_count?: number;
}
export interface MotCle { slug: string; label: string; parent_slug: string | null }

export interface SearchResponse {
  data: DocumentSummary[];
  highlights: Record<string, string>;
  meta: { total: number; page: number; per_page: number; last_page: number; facets: { type?: Partial<Record<DocumentType, number>>; access_mode?: Partial<Record<AccessMode, number>> } };
}

export interface CatalogueFilters {
  type?: DocumentType; specialite?: string; promotion?: number; annee_min?: number; annee_max?: number;
  langue?: string; acces?: AccessMode; mot_cle?: string; tri?: SortKey;
}

export type ApiResult<T> = { ok: true; data: T } | { ok: false; error: { message: string; status: number } };
