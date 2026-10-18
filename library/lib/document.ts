import type { DocumentDetail, DocumentType } from './api/types';
import { documentHref } from './routes';

/** Types décrits comme des livres (schema.org `Book`, Open Graph `book`). */
const BOOK_TYPES: readonly DocumentType[] = ['ouvrage_numerique', 'these', 'memoire'];

export const META_DESCRIPTION_MAX = 160;
const SHORT_TITLE_MAX = 60;
const ELLIPSIS = '…';

export function isBookType(type: DocumentType): boolean {
  return BOOK_TYPES.includes(type);
}

export function schemaType(type: DocumentType): 'Book' | 'ScholarlyArticle' | 'CreativeWork' {
  if (isBookType(type)) return 'Book';
  return type === 'article_scientifique' ? 'ScholarlyArticle' : 'CreativeWork';
}

/** Coupe `text` à `max` caractères au plus (points de suspension compris), sur une fin de mot. */
function truncateOnWord(text: string, max: number): string {
  if (text.length <= max) return text;
  const room = text.slice(0, max - ELLIPSIS.length);
  const lastSpace = room.lastIndexOf(' ');
  const cut = lastSpace > 0 ? room.slice(0, lastSpace) : room;
  return `${cut.replace(/[\s.,;:!?·—–-]+$/u, '')}${ELLIPSIS}`;
}

/** Extrait d'un texte (espaces normalisés) pour la balise description. */
export function excerpt(text: string, max = META_DESCRIPTION_MAX): string {
  return truncateOnWord(text.replace(/\s+/g, ' ').trim(), max);
}

/** Titre abrégé du fil d'Ariane (le titre complet reste le `h1`). */
export function shortTitle(title: string): string {
  return truncateOnWord(title.trim(), SHORT_TITLE_MAX);
}

/** Paragraphes du résumé : séparés par une ligne vide ; les retours simples restent dans le paragraphe. */
export function abstractParagraphs(abstract: string | null): string[] {
  if (!abstract) return [];
  return abstract
    .replace(/\r\n?/g, '\n')
    .split(/\n[ \t]*\n+/)
    .map((p) => p.trim())
    .filter((p) => p.length > 0);
}

const DOI_PREFIX = /^(?:https?:\/\/(?:dx\.)?doi\.org\/|doi:\s*)/i;
const DOI_SHAPE = /^10\.\d{4,9}\/\S.*$/;

/** Lien de résolution d'un DOI (`https://doi.org/…`), ou null si la valeur n'a pas la forme d'un DOI. */
export function doiUrl(doi: string | null): string | null {
  if (!doi) return null;
  const bare = doi.trim().replace(DOI_PREFIX, '');
  return DOI_SHAPE.test(bare) ? `https://doi.org/${encodeURI(bare)}` : null;
}

interface JsonLdContext {
  origin: string;
  publisherName: string;
  publisherUrl: string;
  description?: string;
}

/** Données structurées schema.org de la fiche (sérialisées par `JsonLd`, `<` échappé). */
export function documentJsonLd(doc: DocumentDetail, ctx: JsonLdContext): Record<string, unknown> {
  const type = schemaType(doc.type);
  const doi = doiUrl(doc.doi);
  return {
    '@context': 'https://schema.org',
    '@type': type,
    name: doc.title,
    ...(doc.subtitle ? { alternativeHeadline: doc.subtitle } : {}),
    ...(doc.authors.length > 0 ? { author: doc.authors.map((name) => ({ '@type': 'Person', name })) } : {}),
    ...(doc.year !== null ? { datePublished: String(doc.year) } : {}),
    inLanguage: doc.language,
    isAccessibleForFree: doc.access_mode === 'libre',
    publisher: { '@type': 'Organization', name: ctx.publisherName, url: ctx.publisherUrl },
    url: `${ctx.origin}${documentHref(doc.slug)}`,
    ...(ctx.description ? { description: ctx.description } : {}),
    ...(doc.cover_url ? { image: doc.cover_url } : {}),
    ...(doc.mots_cles.length > 0 ? { keywords: doc.mots_cles.map((m) => m.label).join(', ') } : {}),
    ...(type === 'Book' && doc.isbn ? { isbn: doc.isbn } : {}),
    ...(type === 'Book' && doc.pages_count ? { numberOfPages: doc.pages_count } : {}),
    ...(doi ? { sameAs: doi } : {}),
  };
}
