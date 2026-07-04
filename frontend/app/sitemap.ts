import type { MetadataRoute } from 'next';
import { listArticles } from '@/lib/api/articles';
import { getMenu } from '@/lib/api/pages';

const BASE_URL = process.env.NEXT_PUBLIC_SITE_URL ?? 'https://pssfp.org';

const STATIC_ROUTES: ReadonlyArray<{ path: string; changeFrequency: 'daily' | 'weekly' | 'monthly' | 'yearly'; priority: number }> = [
  { path: '/', changeFrequency: 'weekly', priority: 1.0 },
  { path: '/a-propos', changeFrequency: 'monthly', priority: 0.9 },
  { path: '/formations', changeFrequency: 'monthly', priority: 0.9 },
  { path: '/vie-academique', changeFrequency: 'monthly', priority: 0.8 },
  { path: '/actualites', changeFrequency: 'daily', priority: 0.9 },
  { path: '/contact', changeFrequency: 'yearly', priority: 0.6 },
  { path: '/mentions-legales', changeFrequency: 'yearly', priority: 0.3 },
  { path: '/confidentialite', changeFrequency: 'yearly', priority: 0.3 },
  { path: '/plan-du-site', changeFrequency: 'yearly', priority: 0.3 },
];

/**
 * /sitemap.xml généré dynamiquement par Next.js (cf. spec module 1 PR P).
 *
 * Inclut :
 *  - 9 routes statiques (home, sections, transversales)
 *  - Toutes les pages CMS in_menu via /v1/menu (a-propos/*, formations/*, vie-academique/*)
 *  - Tous les articles publiés via /v1/articles?per_page=100
 *
 * Robuste à un backend down : retourne au moins les routes statiques.
 */
export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const now = new Date();

  const entries: MetadataRoute.Sitemap = STATIC_ROUTES.map((route) => ({
    url: `${BASE_URL}${route.path}`,
    lastModified: now,
    changeFrequency: route.changeFrequency,
    priority: route.priority,
  }));

  // Pages CMS via /v1/menu — récupère récursivement les enfants.
  try {
    const menu = await getMenu();
    if (menu.ok) {
      const flat: { slug: string }[] = [];
      const walk = (nodes: typeof menu.data): void => {
        for (const node of nodes) {
          flat.push({ slug: node.slug });
          if (node.children) walk(node.children);
        }
      };
      walk(menu.data);
      for (const node of flat) {
        // Évite les doublons des routes statiques (/a-propos, /formations, etc.)
        const url = `${BASE_URL}/${node.slug}`;
        if (!entries.some((e) => e.url === url)) {
          entries.push({
            url,
            lastModified: now,
            changeFrequency: 'monthly',
            priority: 0.7,
          });
        }
      }
    }
  } catch {
    // ignore — fallback aux routes statiques
  }

  // Articles via /v1/articles?per_page=100
  try {
    const articles = await listArticles({});
    if (articles.ok) {
      for (const article of articles.data.data) {
        entries.push({
          url: `${BASE_URL}/actualites/${article.slug}`,
          lastModified: article.published_at ? new Date(article.published_at) : now,
          changeFrequency: 'monthly',
          priority: 0.6,
        });
      }
    }
  } catch {
    // ignore
  }

  return entries;
}
