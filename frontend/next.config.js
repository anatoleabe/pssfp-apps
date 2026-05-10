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
      { protocol: 'http', hostname: 'localhost' },
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
    ];
  },
};

module.exports = withNextIntl(nextConfig);
