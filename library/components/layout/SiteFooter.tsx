import Link from 'next/link';
import { ArrowUpRight, Mail, MapPin } from 'lucide-react';
import { getTranslations } from 'next-intl/server';
import { BrandMark } from './BrandMark';
import { FOAD_URL, MAIN_SITE_URL, NAV_ITEMS, SEARCH_HREF } from './nav-config';

/*
 * Fond prune « deep » #2E1C40 : blanc 85 % 11,5:1 · or #D4AF6A 7,5:1 (titres de colonnes, icônes).
 * Anneau de focus or : seul cas où l'or sert d'indicateur (fond sombre).
 */
const LINK =
  'inline-flex min-h-6 items-center gap-1 rounded-sm text-white/85 underline-offset-4 transition-colors hover:text-white hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-or focus-visible:ring-offset-2 focus-visible:ring-offset-pssfp-prune-deep';
const HEADING = 'font-ui text-xs font-medium uppercase tracking-[0.18em] text-pssfp-or';

export async function SiteFooter(): Promise<JSX.Element> {
  const t = await getTranslations('footer');
  const tNav = await getTranslations('nav');
  const year = new Date().getFullYear();
  const explore = [...NAV_ITEMS.filter((item) => item.key !== 'home').map((item) => ({ href: item.href, label: tNav(item.key) })), { href: SEARCH_HREF, label: tNav('search') }];

  return (
    <footer className="relative isolate mt-auto overflow-hidden bg-pssfp-prune-deep text-white">
      {/* Arcs concentriques décoratifs, en écho au logo (sans le reproduire). */}
      <div aria-hidden="true" className="pointer-events-none absolute -right-40 -top-56 -z-10 h-[28rem] w-[28rem] rounded-full border border-white/[0.06] shadow-[0_0_0_4rem_rgba(255,255,255,0.02),0_0_0_8rem_rgba(255,255,255,0.015)]" />
      <div className="mx-auto grid max-w-7xl gap-10 px-4 py-14 sm:px-6 md:grid-cols-2 lg:grid-cols-[1.4fr_1fr_1fr] lg:gap-16 lg:py-16">
        <section aria-labelledby="footer-about">
          <div className="flex items-center gap-4">
            <BrandMark alt="" height={40} onDark />
            <h2 id="footer-about" className="font-heading text-2xl font-semibold leading-tight">
              {t('aboutTitle')}
            </h2>
          </div>
          <p className="mt-5 max-w-md text-sm leading-relaxed text-white/85">{t('aboutBody')}</p>
          <ul className="mt-6 space-y-3 text-sm">
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
          </ul>
        </section>

        <nav aria-labelledby="footer-explore">
          <h2 id="footer-explore" className={HEADING}>
            {t('browseTitle')}
          </h2>
          <ul className="mt-4 space-y-2.5 text-sm">
            {explore.map((item) => (
              <li key={item.href}>
                <Link href={item.href} className={LINK}>
                  {item.label}
                </Link>
              </li>
            ))}
          </ul>
        </nav>

        <nav aria-labelledby="footer-links">
          <h2 id="footer-links" className={HEADING}>
            {t('linksTitle')}
          </h2>
          <p className="mt-4 max-w-xs text-sm leading-relaxed text-white/85">{t('contactBody')}</p>
          <ul className="mt-4 space-y-2.5 text-sm">
            <li>
              <a href={MAIN_SITE_URL} className={LINK}>
                {tNav('mainSite')}
                <ArrowUpRight size={14} aria-hidden="true" />
              </a>
            </li>
            <li>
              <a href={FOAD_URL} className={LINK}>
                {t('foad')}
                <ArrowUpRight size={14} aria-hidden="true" />
              </a>
            </li>
            <li>
              <a href={`${MAIN_SITE_URL}/contact`} className={LINK}>
                {t('contactPage')}
                <ArrowUpRight size={14} aria-hidden="true" />
              </a>
            </li>
          </ul>
        </nav>
      </div>
      <div className="border-t border-white/15">
        <p className="mx-auto max-w-7xl px-4 py-5 font-ui text-xs text-white/75 sm:px-6">{t('copyright', { year })}</p>
      </div>
    </footer>
  );
}
