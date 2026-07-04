import type { MetadataRoute } from 'next';

const BASE_URL = process.env.NEXT_PUBLIC_SITE_URL ?? 'https://pssfp.org';

/**
 * /robots.txt généré dynamiquement par Next.js.
 * Permet aux crawlers d'indexer toutes les pages publiques.
 * Bloque /api/ (au cas où des routes Next.js Route Handler seraient ajoutées).
 */
export default function robots(): MetadataRoute.Robots {
  return {
    rules: [
      {
        userAgent: '*',
        allow: '/',
        // /demo-copil : page interne pour la démonstration au Comité de Pilotage,
        // exclue de l'indexation (cf. spec sprint S5 PR AA).
        disallow: ['/api/', '/demo-copil'],
      },
    ],
    sitemap: `${BASE_URL}/sitemap.xml`,
    host: BASE_URL,
  };
}
