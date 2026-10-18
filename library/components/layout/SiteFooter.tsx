import Link from 'next/link';
import { ArrowUpRight, Mail, MapPin } from 'lucide-react';
import { getTranslations } from 'next-intl/server';
import { Logo } from '@pssfp/ui';
import { DOCUMENT_TYPES } from '@/lib/api/types';
import { collectionHref } from '@/lib/routes';
import { FOAD_URL, MAIN_SITE_URL, NAV_ITEMS } from './nav-config';

const LINK =
  'rounded-sm text-white/85 underline-offset-4 transition-colors hover:text-white hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-or';
const HEADING = 'font-ui text-xs font-medium uppercase tracking-[0.18em] text-pssfp-or';

export async function SiteFooter(): Promise<JSX.Element> {
  const t = await getTranslations('footer');
  const tNav = await getTranslations('nav');
  const tTypes = await getTranslations('types');
  const tMeta = await getTranslations('meta');
  const year = new Date().getFullYear();
  const browse = NAV_ITEMS.filter((item) => item.key !== 'collections');

  return (
    <footer className="mt-auto bg-pssfp-prune text-white">
      <div className="mx-auto grid max-w-7xl gap-10 px-4 py-14 sm:px-6 lg:grid-cols-[1.1fr_2fr_1fr] lg:gap-12">
        <section aria-labelledby="footer-about">
          <div className="flex items-center gap-3">
            <Logo variant="mark" title={tMeta('logoTitle')} aria-hidden="true" className="h-10 w-10 ring-1 ring-white/25 rounded-lg" />
            <h2 id="footer-about" className="font-heading text-2xl font-semibold">
              {t('aboutTitle')}
            </h2>
          </div>
          <p className="mt-4 max-w-sm text-sm leading-relaxed text-white/85">{t('aboutBody')}</p>
        </section>

        <nav aria-label={t('linksLabel')} className="grid gap-8 sm:grid-cols-[2fr_1fr]">
          <div>
            <h2 className={HEADING}>{t('collectionsTitle')}</h2>
            <ul className="mt-4 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
              {DOCUMENT_TYPES.map((type) => (
                <li key={type}>
                  <Link href={collectionHref(type)} className={LINK}>
                    {tTypes(`${type}.other`)}
                  </Link>
                </li>
              ))}
            </ul>
          </div>
          <div>
            <h2 className={HEADING}>{t('browseTitle')}</h2>
            <ul className="mt-4 space-y-2 text-sm">
              {browse.map((item) => (
                <li key={item.key}>
                  <Link href={item.href} className={LINK}>
                    {tNav(item.key)}
                  </Link>
                </li>
              ))}
              <li>
                <a href={MAIN_SITE_URL} className={`${LINK} inline-flex items-center gap-1`}>
                  {tNav('mainSite')}
                  <ArrowUpRight size={14} aria-hidden="true" />
                </a>
              </li>
              {FOAD_URL ? (
                <li>
                  <a href={FOAD_URL} className={`${LINK} inline-flex items-center gap-1`}>
                    {t('foad')}
                    <ArrowUpRight size={14} aria-hidden="true" />
                  </a>
                </li>
              ) : null}
            </ul>
          </div>
        </nav>

        <section aria-labelledby="footer-contact">
          <h2 id="footer-contact" className={HEADING}>
            {t('contactTitle')}
          </h2>
          <p className="mt-4 text-sm leading-relaxed text-white/85">{t('contactBody')}</p>
          <ul className="mt-4 space-y-3 text-sm">
            <li className="flex items-start gap-2 text-white/85">
              <MapPin size={16} aria-hidden="true" className="mt-0.5 shrink-0 text-pssfp-or" />
              <span>{t('contactAddress')}</span>
            </li>
            <li className="flex items-start gap-2">
              <Mail size={16} aria-hidden="true" className="mt-0.5 shrink-0 text-pssfp-or" />
              <a href={`mailto:${t('contactEmail')}`} className={LINK}>
                {t('contactEmail')}
              </a>
            </li>
            <li>
              <a href={`${MAIN_SITE_URL}/contact`} className={`${LINK} inline-flex items-center gap-1`}>
                {t('contactPage')}
                <ArrowUpRight size={14} aria-hidden="true" />
              </a>
            </li>
          </ul>
        </section>
      </div>
      <div className="border-t border-white/15">
        <p className="mx-auto max-w-7xl px-4 py-5 font-ui text-xs text-white/75 sm:px-6">
          {t('copyright', { year })}
        </p>
      </div>
    </footer>
  );
}
