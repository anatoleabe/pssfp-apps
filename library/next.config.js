const createNextIntlPlugin = require('next-intl/plugin');
const withNextIntl = createNextIntlPlugin('./i18n.ts');

/*
 * Build de production : l'URL de l'API est figée dans le bundle client (NEXT_PUBLIC_*). Sans elle,
 * le site appellerait http://localhost:8000/v1 chez les visiteurs : on refuse de construire.
 * La CI (variable CI définie) construit sans API et garde le défaut.
 */
if (process.env.NODE_ENV === 'production' && !process.env.NEXT_PUBLIC_API_URL && !process.env.CI) {
  throw new Error('NEXT_PUBLIC_API_URL est obligatoire pour un build de production (ex. https://api.pssfp.org/v1) : voir library/.env.example.');
}

const SECURITY_HEADERS = [
  { key: 'X-Content-Type-Options', value: 'nosniff' },
  { key: 'Referrer-Policy', value: 'strict-origin-when-cross-origin' },
  { key: 'X-Frame-Options', value: 'DENY' },
  { key: 'Permissions-Policy', value: 'camera=(), microphone=(), geolocation=()' },
];

/** MinIO local : autorisé hors production uniquement. */
const LOCAL_MEDIA_PATTERNS =
  process.env.NODE_ENV === 'production'
    ? []
    : [
        { protocol: 'http', hostname: '127.0.0.1', port: '9000' },
        { protocol: 'http', hostname: 'localhost', port: '9000' },
      ];

/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  poweredByHeader: false,
  transpilePackages: ['@pssfp/ui'],
  images: {
    formats: ['image/avif', 'image/webp'],
    remotePatterns: [
      { protocol: 'https', hostname: 'media.pssfp.org' },
      { protocol: 'https', hostname: '*.pssfp.org' },
      ...LOCAL_MEDIA_PATTERNS,
    ],
  },
  async headers() {
    return [{ source: '/:path*', headers: SECURITY_HEADERS }];
  },
};

module.exports = withNextIntl(nextConfig);
