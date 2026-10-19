'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ArrowUpRight, Menu, Search } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { cn } from '@pssfp/ui';
import { BrandMark } from './BrandMark';
import { MobileDrawer } from './MobileDrawer';
import { FOAD_URL, MAIN_SITE_URL, NAV_ITEMS, SEARCH_HREF, isNavActive } from './nav-config';

/** Point de rupture `lg` de Tailwind : navigation principale visible, tiroir masqué. */
const DESKTOP_QUERY = '(min-width: 1024px)';

const FOCUS_RING = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2';
/** Bandeau sombre : anneau or (7,5:1 sur la prune « ink »). */
const FOCUS_RING_DARK = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-or focus-visible:ring-offset-2 focus-visible:ring-offset-pssfp-prune-ink';

/** Bandeau institutionnel : rattachement au PSSFP et liens vers le site et la FOAD (non collant). */
function InstitutionalBar(): JSX.Element {
  const t = useTranslations('nav');
  const link = cn('inline-flex min-h-6 items-center gap-1 rounded-sm text-white/85 transition-colors hover:text-white', FOCUS_RING_DARK);
  return (
    <div className="bg-pssfp-prune-ink font-ui text-xs text-white/80">
      <div className="mx-auto flex h-9 max-w-7xl items-center justify-between gap-4 px-4 sm:px-6">
        <p className="hidden min-w-0 truncate uppercase tracking-[0.14em] md:block">{t('institutional')}</p>
        <nav aria-label={t('institutionalLabel')} className="ml-auto">
          <ul className="flex items-center gap-5">
            <li>
              <a href={MAIN_SITE_URL} className={link}>
                {t('mainSite')}
                <ArrowUpRight size={13} aria-hidden="true" />
              </a>
            </li>
            <li>
              <a href={FOAD_URL} className={link}>
                {t('foad')}
                <ArrowUpRight size={13} aria-hidden="true" />
              </a>
            </li>
          </ul>
        </nav>
      </div>
    </div>
  );
}

export function SiteHeader(): JSX.Element {
  const t = useTranslations('nav');
  const pathname = usePathname();
  const [open, setOpen] = useState(false);
  const burgerRef = useRef<HTMLButtonElement>(null);
  const searchActive = isNavActive(pathname, SEARCH_HREF);

  // Changement de route : le tiroir se ferme sans voler le focus.
  useEffect(() => {
    setOpen(false);
  }, [pathname]);

  // Passage en affichage large (rotation, fenêtre élargie) : le tiroir, masqué en lg, ne doit plus
  // piéger le focus ni bloquer le défilement. Fermé sans rendre le focus au bouton, masqué lui aussi.
  useEffect(() => {
    if (!open) return;
    const wide = window.matchMedia(DESKTOP_QUERY);
    const closeIfWide = (): void => {
      if (wide.matches) setOpen(false);
    };
    closeIfWide();
    wide.addEventListener('change', closeIfWide);
    return () => wide.removeEventListener('change', closeIfWide);
  }, [open]);

  const closeOnNavigate = useCallback((): void => setOpen(false), []);

  const closeAndRestoreFocus = useCallback((): void => {
    setOpen(false);
    window.requestAnimationFrame(() => burgerRef.current?.focus());
  }, []);

  return (
    <>
      <a
        href="#main"
        className="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-2 focus:z-[60] focus:rounded-pssfp-button focus:bg-pssfp-prune focus:px-3 focus:py-2 focus:font-ui focus:text-sm focus:text-white"
      >
        {t('skip')}
      </a>
      <InstitutionalBar />

      <header
        data-testid="site-header"
        className="sticky top-0 z-40 w-full border-b border-pssfp-lavande/30 bg-white/95 shadow-[0_2px_14px_rgba(74,46,103,0.06)] backdrop-blur supports-[backdrop-filter]:bg-white/90"
      >
        <div className="mx-auto flex h-[4.25rem] max-w-7xl items-center justify-between gap-4 px-4 sm:px-6 lg:h-[4.75rem]">
          <Link href="/" className={cn('group flex min-w-0 items-center gap-2.5 rounded-md sm:gap-3', FOCUS_RING)}>
            <BrandMark alt={t('logoAlt')} height={40} priority />
            <span className="flex min-w-0 flex-col border-l border-pssfp-lavande/50 pl-2.5 leading-none sm:pl-3">
              <span className="truncate font-heading text-xl font-bold sm:text-2xl text-pssfp-prune transition-colors group-hover:text-pssfp-prune-dark">{t('brandName')}</span>
              <span className="mt-1 hidden truncate font-ui text-[0.6875rem] text-pssfp-graphite-light sm:block">{t('brandTagline')}</span>
            </span>
            <span className="sr-only">{t('brandHomeSuffix')}</span>
          </Link>

          <nav aria-label={t('mainLabel')} className="hidden lg:block">
            <ul className="flex items-center gap-1 font-ui text-sm">
              {NAV_ITEMS.map((item) => {
                const active = isNavActive(pathname, item.href);
                return (
                  <li key={item.key}>
                    <Link
                      href={item.href}
                      aria-current={active ? 'page' : undefined}
                      className={cn(
                        'group/nav relative inline-flex h-11 items-center rounded-md px-3 transition-colors',
                        FOCUS_RING,
                        active ? 'font-semibold text-pssfp-prune' : 'text-pssfp-graphite hover:text-pssfp-prune',
                      )}
                    >
                      {t(item.key)}
                      <span
                        aria-hidden="true"
                        className={cn(
                          'pointer-events-none absolute inset-x-3 bottom-0.5 h-[3px] origin-left rounded-full transition-transform duration-300 ease-pssfp-out-expo motion-reduce:transition-none',
                          active ? 'scale-x-100 bg-pssfp-prune' : 'scale-x-0 bg-pssfp-lavande group-hover/nav:scale-x-100',
                        )}
                      />
                    </Link>
                  </li>
                );
              })}
            </ul>
          </nav>

          <div className="flex shrink-0 items-center gap-2">
            <Link
              href={SEARCH_HREF}
              aria-current={searchActive ? 'page' : undefined}
              data-testid="header-search"
              className={cn(
                'inline-flex h-11 min-w-11 items-center justify-center gap-2 rounded-full border px-3 font-ui text-sm font-medium transition-colors sm:px-4',
                FOCUS_RING,
                searchActive
                  ? 'border-pssfp-prune bg-pssfp-prune text-white'
                  : 'border-pssfp-prune/30 bg-pssfp-mist text-pssfp-prune hover:border-pssfp-prune hover:bg-white',
              )}
            >
              <Search size={16} aria-hidden="true" />
              <span className="sr-only sm:not-sr-only">{t('searchCta')}</span>
            </Link>
            <button
              ref={burgerRef}
              type="button"
              onClick={() => setOpen(true)}
              aria-label={t('menuOpen')}
              aria-expanded={open}
              // Référence valide seulement quand le dialogue existe dans le DOM.
              aria-controls={open ? 'mobile-menu' : undefined}
              data-testid="nav-toggle"
              className={cn(
                'inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-pssfp-prune/30 text-pssfp-prune transition-colors hover:border-pssfp-prune lg:hidden',
                FOCUS_RING,
              )}
            >
              <Menu size={20} aria-hidden="true" />
            </button>
          </div>
        </div>
      </header>
      {open && <MobileDrawer pathname={pathname} onClose={closeAndRestoreFocus} onNavigate={closeOnNavigate} />}
    </>
  );
}
