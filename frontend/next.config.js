const createNextIntlPlugin = require('next-intl/plugin');
const withNextIntl = createNextIntlPlugin('./i18n.ts');

/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  poweredByHeader: false,
  transpilePackages: ['@pssfp/ui'],
  images: {
    formats: ['image/avif', 'image/webp'],
    remotePatterns: [
      { protocol: 'https', hostname: 'api.pssfp.net' },
      { protocol: 'https', hostname: 'cdn.pssfp.net' },
      { protocol: 'https', hostname: 'media.pssfp.net' },
      { protocol: 'http', hostname: 'localhost' },
      { protocol: 'http', hostname: '127.0.0.1' },
    ],
  },
  experimental: {
    typedRoutes: false,
  },
  // Sprint S5 PR W : rubrique « Le PSSFP » renommée « À propos de nous ».
  // Redirect 308 (permanent) pour préserver SEO et liens externes pré-existants.
  async redirects() {
    return [
      { source: '/pssfp', destination: '/a-propos', permanent: true },
      { source: '/pssfp/:slug*', destination: '/a-propos/:slug*', permanent: true },
      // Sprint S5.1 — alias de courtoisie pour la variante "mot-du-president" (avec "du")
      // qui circule dans certains documents pré-démo. Slug canonique = "mot-president".
      { source: '/a-propos/mot-du-president', destination: '/a-propos/mot-president', permanent: true },
      { source: '/pssfp/mot-du-president', destination: '/a-propos/mot-president', permanent: true },
    ];
  },
  // Sprint S5.1 — proxie les documents publics (catalogue Formation continue, etc.)
  // vers le backend Laravel qui sert depuis MinIO. Garde l'URL `pssfp.net/documents/*`
  // côté visiteur (URL "propre", pas de subdomain api.* exposé).
  async rewrites() {
    const apiBase = process.env.NEXT_PUBLIC_PUBLIC_DOC_URL ?? 'http://localhost:8000';
    return [
      {
        source: '/documents/:path*',
        destination: `${apiBase}/documents/:path*`,
      },
    ];
  },
};

module.exports = withNextIntl(nextConfig);
