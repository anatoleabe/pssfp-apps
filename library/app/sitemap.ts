import type { MetadataRoute } from 'next';
import { getStats, listDocuments } from '@/lib/api/library';
import type { DocumentType } from '@/lib/api/types';
import { collectionHref, documentHref } from '@/lib/routes';
import { siteUrl } from '@/lib/site';

/** Régénéré au plus toutes les heures (ISR). */
export const revalidate = 3600;

/** Plafond d'entrées de fiches (une page de sitemap en accepte 50 000 ; marge large). */
const MAX_DOCUMENTS = 5000;
const PER_PAGE = 50;

type Entry = MetadataRoute.Sitemap[number];

/**
 * Accueil, à propos, collections non vides et fiches publiées (`lastModified` = `published_at`).
 * `/recherche` est exclu (pages non indexées). API indisponible (CI, panne) : sitemap réduit
 * aux pages fixes, et à ce qui a déjà été lu — jamais d'exception (le build ne doit pas échouer).
 */
export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const origin = siteUrl().origin;
  const url = (path: string): string => `${origin}${path}`;
  const fixed: Entry[] = [
    { url: url('/'), changeFrequency: 'daily', priority: 1 },
    { url: url('/a-propos'), changeFrequency: 'yearly', priority: 0.3 },
  ];

  const [collections, documents] = await Promise.all([collectionEntries(url), documentEntries(url)]);
  return [...fixed, ...collections, ...documents];
}

async function collectionEntries(url: (path: string) => string): Promise<Entry[]> {
  try {
    const r = await getStats();
    if (!r.ok) return [];
    return Object.entries(r.data.data.by_type)
      .filter(([, n]) => (n ?? 0) > 0)
      .map(([type]) => ({ url: url(collectionHref(type as DocumentType)), changeFrequency: 'weekly', priority: 0.8 }));
  } catch {
    return [];
  }
}

async function documentEntries(url: (path: string) => string): Promise<Entry[]> {
  const entries: Entry[] = [];
  try {
    for (let page = 1; entries.length < MAX_DOCUMENTS; page += 1) {
      const r = await listDocuments({ tri: 'recent' }, page, PER_PAGE);
      if (!r.ok) break;
      for (const doc of r.data.data) {
        entries.push({
          url: url(documentHref(doc.slug)),
          ...(doc.published_at ? { lastModified: doc.published_at } : {}),
          changeFrequency: 'monthly',
          priority: 0.6,
        });
      }
      if (r.data.data.length === 0 || page >= r.data.meta.last_page) break;
    }
  } catch {
    // Réponse inattendue : on garde les fiches déjà lues.
  }
  return entries.slice(0, MAX_DOCUMENTS);
}
