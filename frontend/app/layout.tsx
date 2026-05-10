import type { Metadata } from 'next';
import { NextIntlClientProvider } from 'next-intl';
import { getLocale, getMessages } from 'next-intl/server';
import { Playfair_Display, Inter, DM_Sans } from 'next/font/google';
import './globals.css';

const playfair = Playfair_Display({
  subsets: ['latin'],
  display: 'swap',
  variable: '--font-heading',
  weight: ['400', '700'],
});

const inter = Inter({
  subsets: ['latin'],
  display: 'swap',
  variable: '--font-body',
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
    'Site institutionnel du PSSFP, Campus de Messa, Yaoundé — formations supérieures en finances publiques.',
  metadataBase: new URL('https://pssfp.net'),
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
    <html lang={locale} className={`${playfair.variable} ${inter.variable} ${dmSans.variable}`} suppressHydrationWarning>
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
