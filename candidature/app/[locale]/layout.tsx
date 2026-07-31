import type { Metadata } from 'next';
import { NextIntlClientProvider } from 'next-intl';
import { getMessages, getTranslations } from 'next-intl/server';
import { Cormorant_Garamond, Source_Sans_3, DM_Sans } from 'next/font/google';
import { PssfpLogo } from '@pssfp/ui';
import { LogOut, FileText, Home } from 'lucide-react';
import { headers } from 'next/headers';
import { notFound } from 'next/navigation';
import { LanguageSwitcher } from '@/components/LanguageSwitcher';
import { DEFAULT_LOCALE, SUPPORTED_LOCALES, isSupportedLocale } from '@/i18n';
import { Link } from '@/navigation';
import { logoutAction } from './dossier/actions';
import '../globals.css';

const cormorant = Cormorant_Garamond({ subsets: ['latin'], display: 'swap', variable: '--font-heading', weight: ['500', '600', '700'] });
const sourceSans = Source_Sans_3({ subsets: ['latin'], display: 'swap', variable: '--font-body', weight: ['400', '500', '600', '700'] });
const dmSans = DM_Sans({ subsets: ['latin'], display: 'swap', variable: '--font-ui', weight: ['400', '500', '600'] });

const SITE_URL = 'https://apply.pssfp.org';

type LocaleParams = { params: { locale: string } };

/**
 * Pas de `generateStaticParams` : toutes les pages lisent des cookies de
 * session ou l'en-tête `x-candidat-session-valid` posé par le middleware.
 * Les déclarer statiques ferait échouer le prérendu au build.
 */
export async function generateMetadata({ params: { locale } }: LocaleParams): Promise<Metadata> {
  const t = await getTranslations({ locale, namespace: 'meta' });

  // hreflang : chaque version déclare l'autre, et `x-default` pointe le
  // français, langue de référence de l'institution (ADR-0006).
  const languages = Object.fromEntries(
    SUPPORTED_LOCALES.map((l) => [l, l === DEFAULT_LOCALE ? SITE_URL : `${SITE_URL}/${l}`]),
  );

  return {
    title: { default: t('title'), template: `%s — ${t('title')}` },
    description: t('description'),
    metadataBase: new URL(SITE_URL),
    robots: { index: true, follow: true },
    alternates: {
      canonical: locale === DEFAULT_LOCALE ? SITE_URL : `${SITE_URL}/${locale}`,
      languages: { ...languages, 'x-default': SITE_URL },
    },
    openGraph: {
      locale: locale === 'fr' ? 'fr_FR' : 'en_GB',
      alternateLocale: locale === 'fr' ? 'en_GB' : 'fr_FR',
      siteName: t('title'),
    },
  };
}

async function CandidatureHeader() {
  const t = await getTranslations('nav');
  // Le middleware a validé le token auprès de Sanctum. Le header ne se fie
  // jamais à la simple présence d'un cookie potentiellement révoqué.
  const isLoggedIn = (await headers()).get('x-candidat-session-valid') === '1';

  return (
    <>
    <div className="bg-[#0F3A4A] px-4 py-2 text-center text-[11px] font-semibold tracking-wide text-white sm:text-xs">
      <span lang="fr">République du Cameroun — Ministère des Finances</span>{' '}
      <span aria-hidden="true">/</span>{' '}
      <span lang="en">Republic of Cameroon — Ministry of Finance</span>
    </div>
    <header className="sticky top-0 z-40 w-full border-b border-[var(--pssfp-border)] bg-white/80 shadow-pssfp-soft backdrop-blur-2xl">
      <div className="mx-auto flex max-w-5xl items-center justify-between gap-4 px-6 py-4">
        <Link
          href="/"
          aria-label={t('homeAria')}
          className="group flex items-center gap-3 rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2"
        >
          <span className="relative inline-flex transition-transform duration-300 ease-pssfp-out-expo group-hover:scale-105">
            <span
              aria-hidden="true"
              className="absolute inset-0 -z-10 rounded-full bg-[#5C3A7E]/20 opacity-0 blur-xl transition-opacity duration-300 group-hover:opacity-60"
            />
            <PssfpLogo size={40} />
          </span>
        </Link>
        <nav aria-label={t('navAria')} className="flex items-center gap-1 text-sm">
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
                {t('myDossier')}
              </Link>
              <form action={logoutAction}>
                <button
                  type="submit"
                  data-testid="nav-logout"
                  className="inline-flex h-10 items-center gap-1.5 rounded-pssfp-button border border-[var(--pssfp-border)] bg-white px-3 text-pssfp-graphite transition-all duration-200 hover:border-pssfp-prune hover:text-pssfp-prune hover:shadow-pssfp-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2"
                >
                  <LogOut size={14} aria-hidden="true" />
                  {t('logout')}
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
          <LanguageSwitcher />
        </nav>
      </div>
    </header>
    </>
  );
}

async function InstitutionalFooter(): Promise<JSX.Element> {
  const t = await getTranslations('footer');
  const mainSite = process.env.NEXT_PUBLIC_MAIN_SITE_URL ?? 'https://pssfp.org';

  return (
    <footer className="mt-auto border-t border-[#E4DCEE] bg-[#0F3A4A] text-white">
      <div className="mx-auto grid max-w-5xl gap-8 px-6 py-10 md:grid-cols-3">
        <div>
          <p className="font-heading text-xl font-bold">{t('tagline')}</p>
          <p className="mt-2 text-sm leading-relaxed text-white/80">{t('programme')}</p>
        </div>
        <div className="text-sm text-white/85">
          <p className="font-semibold text-white">{t('scolarite')}</p>
          <address className="mt-2 space-y-1 not-italic">
            <p>{t('address')}</p>
            <p><a className="underline hover:text-white" href="tel:+237222234567">+237 222 23 45 67</a></p>
            <p><a className="underline hover:text-white" href="mailto:admissions@pssfp.org">admissions@pssfp.org</a></p>
          </address>
        </div>
        <nav aria-label={t('linksAria')} className="flex flex-col items-start gap-2 text-sm">
          <Link className="underline hover:text-[#E5C98E]" href="/cgu">{t('cgu')}</Link>
          <Link className="underline hover:text-[#E5C98E]" href="/confidentialite">{t('privacy')}</Link>
          <a className="underline hover:text-[#E5C98E]" href={`${mainSite}/formations/admission`}>{t('admission')}</a>
        </nav>
      </div>
    </footer>
  );
}

export default async function RootLayout({
  children,
  params: { locale },
}: { children: React.ReactNode } & LocaleParams) {
  if (!isSupportedLocale(locale)) {
    notFound();
  }

  const messages = await getMessages();

  return (
    <html lang={locale} className={`${cormorant.variable} ${sourceSans.variable} ${dmSans.variable}`}>
      <body className="flex min-h-screen flex-col font-body antialiased">
        <NextIntlClientProvider locale={locale} messages={messages}>
          <CandidatureHeader />
          <main id="main">{children}</main>
          <InstitutionalFooter />
        </NextIntlClientProvider>
      </body>
    </html>
  );
}
