'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ArrowUpRight, Menu } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { cn, Logo } from '@pssfp/ui';
import { MobileDrawer } from './MobileDrawer';
import { MAIN_SITE_URL, NAV_ITEMS, isNavActive } from './nav-config';

const FOCUS_RING =
  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2';

export function SiteHeader(): JSX.Element {
  const t = useTranslations('nav');
  const tMeta = useTranslations('meta');
  const pathname = usePathname();
  const [open, setOpen] = useState(false);
  const burgerRef = useRef<HTMLButtonElement>(null);

  // Changement de route : le tiroir se ferme sans voler le focus.
  useEffect(() => {
    setOpen(false);
  }, [pathname]);

  const closeAndRestoreFocus = useCallback((): void => {
    setOpen(false);
    window.requestAnimationFrame(() => burgerRef.current?.focus());
  }, []);

  return (
    <>
      <header
        data-testid="site-header"
        className="sticky top-0 z-40 w-full border-b border-pssfp-lavande/30 bg-white/95 backdrop-blur supports-[backdrop-filter]:bg-white/85"
      >
        <a
          href="#main"
          className="sr-only focus:not-sr-only focus:absolute focus:left-3 focus:top-2 focus:z-50 focus:rounded-pssfp-button focus:bg-pssfp-prune focus:px-3 focus:py-2 focus:font-ui focus:text-sm focus:text-white"
        >
          {t('skip')}
        </a>

        <div className="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
          <Link href="/" className={cn('group flex min-w-0 items-center gap-3 rounded-sm', FOCUS_RING)}>
            <Logo
              variant="mark"
              title={tMeta('logoTitle')}
              aria-hidden="true"
              className="h-9 w-9 shrink-0 transition-transform duration-300 ease-pssfp-out-expo group-hover:-rotate-3"
            />
            <span className="flex min-w-0 flex-col leading-none">
              <span className="font-ui text-[0.6875rem] font-medium uppercase tracking-[0.18em] text-pssfp-bleu-petrole">
                {t('brandEyebrow')}
              </span>
              <span className="truncate font-heading text-xl font-bold text-pssfp-prune">{t('brandName')}</span>
            </span>
            <span className="sr-only">{t('brandHomeSuffix')}</span>
          </Link>

          <nav aria-label={t('mainLabel')} className="hidden md:block">
            <ul className="flex items-center gap-1 font-ui text-sm">
              {NAV_ITEMS.map((item) => {
                const active = isNavActive(pathname, item.href);
                return (
                  <li key={item.key}>
                    <Link
                      href={item.href}
                      aria-current={active ? 'page' : undefined}
                      className={cn(
                        'group/nav relative inline-flex h-10 items-center rounded-md px-3 transition-colors',
                        FOCUS_RING,
                        active ? 'font-semibold text-pssfp-prune' : 'text-pssfp-graphite hover:text-pssfp-prune',
                      )}
                    >
                      {t(item.key)}
                      <span
                        aria-hidden="true"
                        className={cn(
                          'pointer-events-none absolute inset-x-3 bottom-1.5 h-0.5 origin-left rounded-full bg-pssfp-or transition-transform duration-300 ease-pssfp-out-expo',
                          active ? 'scale-x-100' : 'scale-x-0 group-hover/nav:scale-x-100',
                        )}
                      />
                    </Link>
                  </li>
                );
              })}
              <li aria-hidden="true" className="mx-2 h-5 w-px bg-pssfp-lavande/50" />
              <li>
                <a
                  href={MAIN_SITE_URL}
                  className={cn(
                    'inline-flex h-10 items-center gap-1.5 rounded-pssfp-button border border-pssfp-prune/25 px-3.5 text-pssfp-prune transition-colors hover:border-pssfp-prune hover:bg-pssfp-prune hover:text-white',
                    FOCUS_RING,
                  )}
                >
                  {t('mainSite')}
                  <ArrowUpRight size={14} aria-hidden="true" />
                </a>
              </li>
            </ul>
          </nav>

          <button
            ref={burgerRef}
            type="button"
            onClick={() => setOpen(true)}
            aria-label={t('menuOpen')}
            aria-expanded={open}
            aria-controls="mobile-menu"
            data-testid="nav-toggle"
            className={cn(
              'inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-pssfp-button border border-pssfp-prune/25 text-pssfp-prune transition-colors hover:border-pssfp-prune md:hidden',
              FOCUS_RING,
            )}
          >
            <Menu size={20} aria-hidden="true" />
          </button>
        </div>
      </header>
      {open && <MobileDrawer pathname={pathname} onClose={closeAndRestoreFocus} />}
    </>
  );
}
