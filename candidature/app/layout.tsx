import type { Metadata } from 'next';
import { NextIntlClientProvider } from 'next-intl';
import { getLocale, getMessages, getTranslations } from 'next-intl/server';
import { Playfair_Display, Inter, DM_Sans } from 'next/font/google';
import { Logo } from '@pssfp/ui';
import Link from 'next/link';
import './globals.css';

const playfair = Playfair_Display({ subsets: ['latin'], display: 'swap', variable: '--font-heading', weight: ['400', '700'] });
const inter = Inter({ subsets: ['latin'], display: 'swap', variable: '--font-body' });
const dmSans = DM_Sans({ subsets: ['latin'], display: 'swap', variable: '--font-ui', weight: ['400', '500', '600'] });

export const metadata: Metadata = {
  title: { default: 'Candidature PSSFP', template: '%s — Candidature PSSFP' },
  description: 'Formulaire de candidature en ligne du PSSFP — promotion 14, campagne 2026.',
  metadataBase: new URL('https://candidature.pssfp.net'),
  robots: { index: true, follow: true },
};

async function CandidatureHeader() {
  const t = await getTranslations('nav');
  return (
    <header className="border-b border-gray-200 bg-white">
      <div className="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
        <Link href="/" aria-label="Candidature PSSFP — Accueil" className="flex items-center gap-3">
          <Logo variant="mark" />
          <span className="font-heading text-lg font-bold text-[#6B2FA0]">Candidature</span>
        </Link>
        <nav aria-label="Navigation candidature" className="flex items-center gap-6 text-sm">
          <Link href="/" className="text-[#333333] hover:text-[#6B2FA0]">{t('home')}</Link>
          <Link href="/login" className="text-[#333333] hover:text-[#6B2FA0]">{t('login')}</Link>
        </nav>
      </div>
    </header>
  );
}

export default async function RootLayout({ children }: { children: React.ReactNode }) {
  const locale = await getLocale();
  const messages = await getMessages();
  return (
    <html lang={locale} className={`${playfair.variable} ${inter.variable} ${dmSans.variable}`}>
      <body className="font-body antialiased">
        <NextIntlClientProvider locale={locale} messages={messages}>
          <CandidatureHeader />
          <main id="main">{children}</main>
        </NextIntlClientProvider>
      </body>
    </html>
  );
}
