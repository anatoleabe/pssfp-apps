import type { MetadataRoute } from 'next';
import { siteUrl } from '@/lib/site';

/** `/dev/` : pages internes ; `/recherche` : résultats non indexés (contenu dérivé de la requête). */
export default function robots(): MetadataRoute.Robots {
  const origin = siteUrl().origin;
  return {
    rules: { userAgent: '*', allow: '/', disallow: ['/dev/', '/recherche'] },
    sitemap: `${origin}/sitemap.xml`,
  };
}
