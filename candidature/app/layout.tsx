import type { Metadata } from 'next';
import { NextIntlClientProvider } from 'next-intl';
import { getLocale, getMessages, getTranslations } from 'next-intl/server';
import { Playfair_Display, Inter, DM_Sans } from 'next/font/google';
import { PssfpLogo } from '@pssfp/ui';
import { LogOut, FileText, Home } from 'lucide-react';
import Link from 'next/link';
import { logoutAction } from './dossier/actions';
import { getCandidatToken } from '@/lib/auth/session';
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
  const isLoggedIn = (await getCandidatToken()) !== null;

  return (
    <header className="sticky top-0 z-40 w-full border-b border-[#EDE7F6] bg-white/80 shadow-pssfp-soft backdrop-blur-2xl">
      <div className="mx-auto flex max-w-5xl items-center justify-between gap-4 px-6 py-4">
        <Link
          href="/"
          aria-label="Candidature PSSFP — Accueil"
          className="group flex items-center gap-3 rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2"
        >
          <span className="relative inline-flex transition-transform duration-300 ease-pssfp-out-expo group-hover:scale-105">
            <span
              aria-hidden="true"
              className="absolute inset-0 -z-10 rounded-full opacity-0 blur-xl transition-opacity duration-300 group-hover:opacity-60"
              style={{ background: 'radial-gradient(circle, #9B59B6 0%, transparent 70%)' }}
            />
            <PssfpLogo size={40} />
          </span>
          <span className="hidden font-heading text-lg font-bold sm:block">
            <span className="text-[#1A0A2E]">PSSFP</span>
            <span className="ml-1.5 text-[#6B2FA0]">/ Candidature</span>
          </span>
        </Link>
        <nav aria-label="Navigation candidature" className="flex items-center gap-1 text-sm">
          <Link
            href="/"
            className="inline-flex h-10 items-center gap-1.5 rounded-pssfp-button px-3 text-[#333] transition-all duration-200 hover:bg-[#EDE7F6] hover:text-[#6B2FA0] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2"
          >
            <Home size={14} aria-hidden="true" className="hidden sm:inline" />
            {t('home')}
          </Link>
          {isLoggedIn ? (
            <>
              <Link
                href="/dossier"
                data-testid="nav-dossier"
                className="inline-flex h-10 items-center gap-1.5 rounded-pssfp-button px-3 text-[#333] transition-all duration-200 hover:bg-[#EDE7F6] hover:text-[#6B2FA0] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2"
              >
                <FileText size={14} aria-hidden="true" className="hidden sm:inline" />
                Mon dossier
              </Link>
              <form action={logoutAction}>
                <button
                  type="submit"
                  data-testid="nav-logout"
                  className="inline-flex h-10 items-center gap-1.5 rounded-pssfp-button border border-[#EDE7F6] bg-white px-3 text-[#333] transition-all duration-200 hover:border-[#6B2FA0] hover:text-[#6B2FA0] hover:shadow-pssfp-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2"
                >
                  <LogOut size={14} aria-hidden="true" />
                  Se déconnecter
                </button>
              </form>
            </>
          ) : (
            <Link
              href="/login"
              className="group relative inline-flex h-10 items-center gap-1.5 overflow-hidden rounded-pssfp-button bg-gradient-violet-or px-4 text-sm font-medium text-white shadow-pssfp-elevated transition-all duration-200 hover:-translate-y-0.5 hover:shadow-pssfp-floating focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C9A227] focus-visible:ring-offset-2"
            >
              <span
                aria-hidden="true"
                className="absolute inset-0 -translate-x-full bg-gradient-to-r from-transparent via-white/25 to-transparent transition-transform duration-700 group-hover:translate-x-full"
              />
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
