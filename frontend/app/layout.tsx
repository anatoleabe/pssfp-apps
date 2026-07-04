import type { Metadata } from 'next';
import { NextIntlClientProvider } from 'next-intl';
import { getLocale, getMessages } from 'next-intl/server';
import { Cormorant_Garamond, Source_Sans_3, DM_Sans } from 'next/font/google';
import './globals.css';

/**
 * Charte 2026 PSSFP — ADR-0008.
 * Cormorant Garamond (titres) + Source Sans 3 (corps + UI) + DM Sans (micro-UI letter-spaced).
 * Remplace Playfair Display + Inter de la charte CDC v5 §10.1 d'origine.
 */
const cormorant = Cormorant_Garamond({
  subsets: ['latin'],
  display: 'swap',
  variable: '--font-heading',
  weight: ['500', '600', '700'],
});

const sourceSans = Source_Sans_3({
  subsets: ['latin'],
  display: 'swap',
  variable: '--font-body',
  weight: ['400', '500', '600', '700'],
});

const dmSans = DM_Sans({
  subsets: ['latin'],
  display: 'swap',
  variable: '--font-ui',
  weight: ['400', '500', '600'],
});

export const metadata: Metadata = {
  title: {
    default: 'PSSFP — Programme Supérieur de Spécialisation en Finances Publiques',
    template: '%s — PSSFP',
  },
  description:
    'Former. Moderniser. Transformer les finances publiques. Site institutionnel du PSSFP, Campus de Messa, Yaoundé.',
  metadataBase: new URL('https://pssfp.org'),
  openGraph: {
    locale: 'fr_FR',
    type: 'website',
    siteName: 'PSSFP',
  },
  robots: {
    index: true,
    follow: true,
  },
};

export default async function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  const locale = await getLocale();
  const messages = await getMessages();

  return (
    <html lang={locale} className={`${cormorant.variable} ${sourceSans.variable} ${dmSans.variable}`} suppressHydrationWarning>
      <head>
        {/* No-flash dark mode init — runs before paint to set html.dark from localStorage / prefers-color-scheme */}
        <script
          dangerouslySetInnerHTML={{
            __html: `(function(){try{var s=localStorage.getItem('pssfp-theme');var p=window.matchMedia('(prefers-color-scheme: dark)').matches;var d=s==='dark'||(s==null&&p);if(d){document.documentElement.classList.add('dark')}}catch(e){}})();`,
          }}
        />
      </head>
      <body className="font-body antialiased">
        <NextIntlClientProvider locale={locale} messages={messages}>
          {children}
        </NextIntlClientProvider>
      </body>
    </html>
  );
}
