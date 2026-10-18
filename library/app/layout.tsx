import type { Metadata } from 'next';
import { NextIntlClientProvider } from 'next-intl';
import { getLocale, getMessages, getTranslations } from 'next-intl/server';
import { Cormorant_Garamond, Source_Sans_3, DM_Sans } from 'next/font/google';
import { SiteHeader } from '@/components/layout/SiteHeader';
import { SiteFooter } from '@/components/layout/SiteFooter';
import { siteUrl } from '@/lib/site';
import './globals.css';

const cormorant = Cormorant_Garamond({ subsets: ['latin'], display: 'swap', variable: '--font-heading', weight: ['500', '600', '700'] });
const sourceSans = Source_Sans_3({ subsets: ['latin'], display: 'swap', variable: '--font-body', weight: ['400', '500', '600', '700'] });
const dmSans = DM_Sans({ subsets: ['latin'], display: 'swap', variable: '--font-ui', weight: ['400', '500', '600'] });


export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations('meta');
  return {
    metadataBase: siteUrl(),
    title: { default: t('titleDefault'), template: t('titleTemplate') },
    description: t('description'),
    applicationName: t('siteName'),
    robots: { index: true, follow: true },
    openGraph: { siteName: t('siteName'), locale: 'fr_FR', type: 'website' },
  };
}

export default async function RootLayout({ children }: { children: React.ReactNode }): Promise<JSX.Element> {
  const locale = await getLocale();
  const messages = await getMessages();
  return (
    <html lang={locale} className={`${cormorant.variable} ${sourceSans.variable} ${dmSans.variable}`}>
      <body className="flex min-h-screen flex-col bg-pssfp-ivoire font-body text-pssfp-graphite antialiased">
        <NextIntlClientProvider locale={locale} messages={messages}>
          <SiteHeader />
          <main id="main" tabIndex={-1} className="flex-1 focus:outline-none">
            {children}
          </main>
          <SiteFooter />
        </NextIntlClientProvider>
      </body>
    </html>
  );
}
