import type { Metadata } from 'next';
import { NextIntlClientProvider } from 'next-intl';
import { getLocale, getMessages, getTranslations } from 'next-intl/server';
import { Cormorant_Garamond, Source_Sans_3, DM_Sans } from 'next/font/google';
import { Logo } from '@pssfp/ui';
import Link from 'next/link';
import './globals.css';

const cormorant = Cormorant_Garamond({ subsets: ['latin'], display: 'swap', variable: '--font-heading', weight: ['500', '600', '700'] });
const sourceSans = Source_Sans_3({ subsets: ['latin'], display: 'swap', variable: '--font-body', weight: ['400', '500', '600', '700'] });
const dmSans = DM_Sans({ subsets: ['latin'], display: 'swap', variable: '--font-ui', weight: ['400', '500', '600'] });

export const metadata: Metadata = {
  title: { default: 'Bibliothèque virtuelle PSSFP', template: '%s — Bibliothèque PSSFP' },
  description: 'Catalogue documentaire du PSSFP : thèses, articles, législation, cours, conférences.',
  metadataBase: new URL('https://bibliotheque.pssfp.net'),
  robots: { index: true, follow: true },
};

async function LibraryHeader() {
  const t = await getTranslations('nav');
  return (
    <header className="border-b border-[var(--pssfp-border)] bg-white">
      <div className="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
        <Link href="/" aria-label="Bibliothèque PSSFP — Accueil" className="flex items-center gap-3">
          <Logo variant="mark" />
          <span className="font-heading text-lg font-bold text-pssfp-prune">Bibliothèque</span>
        </Link>
        <nav aria-label="Navigation bibliothèque" className="flex items-center gap-6 text-sm">
          <Link href="/" className="text-pssfp-graphite hover:text-pssfp-prune">{t('home')}</Link>
          <a href={process.env.NEXT_PUBLIC_MAIN_SITE_URL ?? '#'} className="text-pssfp-graphite hover:text-pssfp-prune">
            pssfp.net
          </a>
        </nav>
      </div>
    </header>
  );
}

export default async function RootLayout({ children }: { children: React.ReactNode }) {
  const locale = await getLocale();
  const messages = await getMessages();
  return (
    <html lang={locale} className={`${cormorant.variable} ${sourceSans.variable} ${dmSans.variable}`}>
      <body className="font-body antialiased">
        <NextIntlClientProvider locale={locale} messages={messages}>
          <LibraryHeader />
          <main id="main">{children}</main>
        </NextIntlClientProvider>
      </body>
    </html>
  );
}
