const createNextIntlPlugin = require('next-intl/plugin');
const withNextIntl = createNextIntlPlugin('./i18n.ts');

/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  poweredByHeader: false,
  transpilePackages: ['@pssfp/ui'],
  // Le portail expose une action de retrait de candidature : sans en-tête
  // anti-encadrement, elle est exposée au détournement de clic (audit §6.1).
  // Le site institutionnel servait déjà cette protection, pas celui-ci.
  async headers() {
    return [
      {
        source: '/:path*',
        headers: [
          { key: 'X-Frame-Options', value: 'DENY' },
          { key: 'Content-Security-Policy', value: "frame-ancestors 'none'" },
          { key: 'X-Content-Type-Options', value: 'nosniff' },
          { key: 'Referrer-Policy', value: 'strict-origin-when-cross-origin' },
        ],
      },
    ];
  },

  images: {
    formats: ['image/avif', 'image/webp'],
    remotePatterns: [
      { protocol: 'https', hostname: 'api.pssfp.net' },
      { protocol: 'http', hostname: 'localhost' },
    ],
  },
};

module.exports = withNextIntl(nextConfig);
