import type { Metadata } from 'next';
import { NextIntlClientProvider } from 'next-intl';
import { getLocale, getMessages, getTranslations } from 'next-intl/server';
import { Cormorant_Garamond, Source_Sans_3, DM_Sans } from 'next/font/google';
import { PssfpLogo } from '@pssfp/ui';
import { LogOut, FileText, Home } from 'lucide-react';
import Link from 'next/link';
import { logoutAction } from './dossier/actions';
import { getCandidatToken } from '@/lib/auth/session';
import './globals.css';

const cormorant = Cormorant_Garamond({ subsets: ['latin'], display: 'swap', variable: '--font-heading', weight: ['500', '600', '700'] });
const sourceSans = Source_Sans_3({ subsets: ['latin'], display: 'swap', variable: '--font-body', weight: ['400', '500', '600', '700'] });
const dmSans = DM_Sans({ subsets: ['latin'], display: 'swap', variable: '--font-ui', weight: ['400', '500', '600'] });

export const metadata: Metadata = {
  title: { default: 'Candidature PSSFP', template: '%s — Candidature PSSFP' },
  description: 'Formulaire de candidature en ligne du PSSFP — promotion 14, campagne 2026.',
  metadataBase: new URL('https://apply.pssfp.org'),
  robots: { index: true, follow: true },
};

async function CandidatureHeader() {
  const t = await getTranslations('nav');
  const isLoggedIn = (await getCandidatToken()) !== null;

  return (
    <header className="sticky top-0 z-40 w-full border-b border-[var(--pssfp-border)] bg-white/80 shadow-pssfp-soft backdrop-blur-2xl">
      <div className="mx-auto flex max-w-5xl items-center justify-between gap-4 px-6 py-4">
        <Link
          href="/"
          aria-label="Candidature PSSFP — Accueil"
          className="group flex items-center gap-3 rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2"
        >
          <span className="relative inline-flex transition-transform duration-300 ease-pssfp-out-expo group-hover:scale-105">
            <span
              aria-hidden="true"
              className="absolute inset-0 -z-10 rounded-full bg-[#5C3A7E]/20 opacity-0 blur-xl transition-opacity duration-300 group-hover:opacity-60"
            />
            <PssfpLogo size={40} />
          </span>
          <span className="hidden font-heading text-lg font-bold sm:block">
            <span className="text-pssfp-graphite">PSSFP</span>
            <span className="ml-1.5 text-pssfp-prune">/ Candidature</span>
          </span>
        </Link>
        <nav aria-label="Navigation candidature" className="flex items-center gap-1 text-sm">
          <Link
            href="/"
            className="inline-flex h-10 items-center gap-1.5 rounded-pssfp-button px-3 text-pssfp-graphite transition-all duration-200 hover:bg-[var(--pssfp-primary-soft)] hover:text-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2"
          >
            <Home size={14} aria-hidden="true" className="hidden sm:inline" />
            {t('home')}
          </Link>
          {isLoggedIn ? (
            <>
              <Link
                href="/dossier"
                data-testid="nav-dossier"
                className="inline-flex h-10 items-center gap-1.5 rounded-pssfp-button px-3 text-pssfp-graphite transition-all duration-200 hover:bg-[var(--pssfp-primary-soft)] hover:text-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2"
              >
                <FileText size={14} aria-hidden="true" className="hidden sm:inline" />
                Mon dossier
              </Link>
              <form action={logoutAction}>
                <button
                  type="submit"
                  data-testid="nav-logout"
                  className="inline-flex h-10 items-center gap-1.5 rounded-pssfp-button border border-[var(--pssfp-border)] bg-white px-3 text-pssfp-graphite transition-all duration-200 hover:border-pssfp-prune hover:text-pssfp-prune hover:shadow-pssfp-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2"
                >
                  <LogOut size={14} aria-hidden="true" />
                  Se déconnecter
                </button>
              </form>
            </>
          ) : (
            <Link
              href="/login"
              className="group relative inline-flex h-10 items-center gap-1.5 overflow-hidden rounded-pssfp-button bg-[#4A2E67] px-4 text-sm font-medium text-white shadow-pssfp-elevated transition-all duration-200 hover:-translate-y-0.5 hover:bg-[#3A2452] hover:shadow-pssfp-floating focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-or focus-visible:ring-offset-2"
            >
              <span className="relative">{t('login')}</span>
            </Link>
          )}
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
          <CandidatureHeader />
          <main id="main">{children}</main>
        </NextIntlClientProvider>
      </body>
    </html>
  );
}
