'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useEffect, useRef, useState } from 'react';
import { ArrowRight, ChevronDown, ExternalLink, Menu, X } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { PssfpLogo } from '@pssfp/ui';
import { cn } from '../../lib/cn';
import { ThemeToggle } from '../ThemeToggle';
import { MegaMenu } from './MegaMenu';
import { MobileDrawer } from './MobileDrawer';
import { NAV_LINKS } from './nav-config';

export function SiteHeader(): JSX.Element {
  const t = useTranslations('nav');
  const pathname = usePathname();
  const [open, setOpen] = useState(false);
  const [scrolled, setScrolled] = useState(false);
  const [scrollProgress, setScrollProgress] = useState(0);
  const [openSubmenu, setOpenSubmenu] = useState<string | null>(null);
  const submenuTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const [mobileExpanded, setMobileExpanded] = useState<string | null>(null);

  useEffect(() => {
    const onScroll = (): void => {
      const y = window.scrollY;
      setScrolled(y > 8);
      const docH = document.documentElement.scrollHeight - window.innerHeight;
      setScrollProgress(docH > 0 ? Math.min(100, Math.max(0, (y / docH) * 100)) : 0);
    };
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  useEffect(() => {
    setOpen(false);
    setOpenSubmenu(null);
    setMobileExpanded(null);
  }, [pathname]);

  useEffect(() => {
    document.body.style.overflow = open ? 'hidden' : '';
    return () => {
      document.body.style.overflow = '';
    };
  }, [open]);

  const isActive = (href: string): boolean => {
    if (href === '/') return pathname === '/';
    return pathname === href || pathname.startsWith(`${href}/`);
  };

  const handleSubmenuOpen = (key: string): void => {
    if (submenuTimer.current) clearTimeout(submenuTimer.current);
    setOpenSubmenu(key);
  };

  const handleSubmenuClose = (): void => {
    if (submenuTimer.current) clearTimeout(submenuTimer.current);
    submenuTimer.current = setTimeout(() => setOpenSubmenu(null), 200);
  };

  const handleMobileToggle = (key: string): void => {
    setMobileExpanded((prev) => (prev === key ? null : key));
  };

  return (
    <>
    <header
      data-testid="site-header"
      className={cn(
        'sticky top-0 z-40 w-full transition-all duration-500 ease-pssfp-out-expo',
        scrolled
          ? 'border-b border-[#C9A040]/40 bg-white/70 shadow-pssfp-elevated backdrop-blur-2xl backdrop-saturate-150 supports-[backdrop-filter]:bg-white/55 dark:border-[#C9A040]/25 dark:bg-[#2D1454]/60 dark:supports-[backdrop-filter]:bg-[#2D1454]/45'
          : 'border-b border-transparent bg-white/95 backdrop-blur-2xs dark:bg-[#14101A]/80',
      )}
    >
      <a
        href="#main"
        className="sr-only focus:not-sr-only focus:absolute focus:left-3 focus:top-2 focus:rounded-md focus:bg-[#4A2E67] focus:px-3 focus:py-2 focus:text-sm focus:text-white"
      >
        {t('skip')}
      </a>

      <div className="mx-auto flex max-w-7xl items-center justify-between gap-4 px-6 py-3 md:py-4">
        <Link
          href="/"
          aria-label="PSSFP — Accueil"
          className="group flex items-center gap-3 rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2"
        >
          <span className="relative inline-flex transition-transform duration-300 ease-pssfp-out-expo group-hover:scale-105">
            <span
              aria-hidden="true"
              className="absolute inset-0 -z-10 rounded-full opacity-0 blur-xl transition-opacity duration-300 group-hover:opacity-60"
              style={{ background: 'radial-gradient(circle, #5C3A7E 0%, transparent 70%)' }}
            />
            <PssfpLogo size={48} />
          </span>
          <span className="hidden font-heading text-base font-bold text-[#4A2E67] dark:text-[#B084E8] sm:block">
            PSSFP
          </span>
        </Link>

        <nav aria-label="Navigation principale" className="hidden lg:block">
          <ul className="flex items-center gap-1 text-sm">
            {NAV_LINKS.map((link) => {
              const active = isActive(link.href);
              const hasChildren = (link.children ?? []).length > 0;
              const isSubmenuOpen = openSubmenu === link.key;
              return (
                <li
                  key={link.href}
                  className="relative"
                  onMouseEnter={hasChildren ? () => handleSubmenuOpen(link.key) : undefined}
                  onMouseLeave={hasChildren ? handleSubmenuClose : undefined}
                >
                  <Link
                    href={link.href}
                    data-testid={`nav-${link.key}`}
                    aria-current={active ? 'page' : undefined}
                    aria-haspopup={hasChildren ? 'menu' : undefined}
                    aria-expanded={hasChildren ? isSubmenuOpen : undefined}
                    onFocus={hasChildren ? () => handleSubmenuOpen(link.key) : undefined}
                    className={cn(
                      'group/nav relative inline-flex h-10 items-center gap-1 rounded-md px-3 transition-colors duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2 dark:focus-visible:ring-[#E5C788]',
                      active
                        ? 'font-semibold text-[#4A2E67] dark:text-[#B084E8]'
                        : 'text-[#333] hover:text-[#4A2E67] dark:text-[#F5EFE3] dark:hover:text-[#B084E8]',
                    )}
                  >
                    {t(link.key)}
                    {hasChildren && (
                      <ChevronDown
                        size={14}
                        aria-hidden="true"
                        className={cn(
                          'transition-transform duration-300 ease-pssfp-out-expo',
                          isSubmenuOpen && 'rotate-180',
                        )}
                      />
                    )}
                    <span
                      aria-hidden="true"
                      className={cn(
                        'pointer-events-none absolute inset-x-2 bottom-1 h-0.5 origin-left rounded-full bg-[linear-gradient(90deg,#4A2E67_0%,#5C3A7E_50%,#C9A040_100%)] transition-transform duration-300 ease-pssfp-out-expo motion-reduce:transition-none',
                        active
                          ? 'scale-x-100'
                          : 'scale-x-0 group-hover/nav:scale-x-100 group-focus-visible/nav:scale-x-100',
                      )}
                    />
                  </Link>

                  {hasChildren && isSubmenuOpen && (
                    <MegaMenu
                      link={link}
                      pathname={pathname}
                      onItemFocus={() => handleSubmenuOpen(link.key)}
                      onItemBlur={handleSubmenuClose}
                    />
                  )}
                </li>
              );
            })}
          </ul>
        </nav>

        <div className="hidden items-center gap-2 lg:flex">
          <ThemeToggle testId="theme-toggle" />
          <a
            href={process.env.NEXT_PUBLIC_LIBRARY_URL ?? '#'}
            className="inline-flex h-10 items-center gap-1.5 rounded-pssfp-button border border-[#D8C9A6] bg-white px-3.5 text-sm text-[#333] transition-all duration-200 hover:border-[#4A2E67] hover:text-[#4A2E67] hover:shadow-pssfp-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2 dark:border-[#3A2F48] dark:bg-[#1F1A28] dark:text-[#F5EFE3] dark:hover:border-[#B084E8] dark:hover:text-[#B084E8]"
            data-testid="nav-library"
          >
            {t('library')}
            <ExternalLink size={14} aria-hidden="true" />
          </a>
          <span className="group/cta relative inline-flex">
            <span
              aria-hidden="true"
              className="pointer-events-none absolute -inset-1 rounded-pssfp-button bg-[radial-gradient(circle_at_50%_50%,rgba(212, 175, 106,0.55)_0%,rgba(74, 46, 103,0.35)_45%,transparent_70%)] opacity-0 blur-lg transition-opacity duration-500 ease-pssfp-out-expo motion-reduce:transition-none group-hover/cta:opacity-100 group-focus-within/cta:opacity-100"
            />
            <a
              href={process.env.NEXT_PUBLIC_CANDIDATURE_URL ?? '#'}
              className="group relative inline-flex h-10 items-center gap-1.5 overflow-hidden rounded-pssfp-button bg-[linear-gradient(135deg,#2D1454_0%,#4A2E67_55%,#C9A040_100%)] bg-[length:200%_200%] px-4 text-sm font-medium text-white shadow-pssfp-elevated transition-all duration-300 ease-pssfp-out-expo hover:-translate-y-0.5 hover:bg-[position:100%_100%] hover:shadow-pssfp-floating focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C9A040] focus-visible:ring-offset-2 motion-reduce:transition-none"
              data-testid="nav-candidature"
            >
              <span
                aria-hidden="true"
                className="absolute -inset-px rounded-pssfp-button opacity-0 ring-1 ring-inset ring-[#C9A040]/40 transition-opacity duration-300 group-hover:opacity-100"
              />
              <span
                aria-hidden="true"
                className="absolute inset-0 -translate-x-full bg-gradient-to-r from-transparent via-white/30 to-transparent transition-transform duration-700 group-hover:translate-x-full"
              />
              <span className="relative">{t('candidature')}</span>
              <ArrowRight
                size={14}
                aria-hidden="true"
                className="relative transition-transform duration-200 group-hover:translate-x-0.5"
              />
            </a>
          </span>
        </div>

        <div className="flex items-center gap-2 lg:hidden">
          <ThemeToggle testId="theme-toggle-mobile" />
          <button
            type="button"
            onClick={() => setOpen((v) => !v)}
            aria-label={open ? t('menuClose') : t('menuOpen')}
            aria-expanded={open}
            aria-controls="mobile-menu"
            data-testid="nav-toggle"
            className="inline-flex h-10 w-10 items-center justify-center rounded-md border border-[#D8C9A6] text-[#333] transition-colors hover:border-[#4A2E67] hover:text-[#4A2E67] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2 dark:border-[#3A2F48] dark:text-[#F5EFE3] dark:hover:border-[#B084E8] dark:hover:text-[#B084E8]"
          >
            {open ? <X size={20} aria-hidden="true" /> : <Menu size={20} aria-hidden="true" />}
          </button>
        </div>
      </div>

      <div
        aria-hidden="true"
        className={cn(
          'pointer-events-none h-px origin-left bg-[linear-gradient(90deg,#2D1454_0%,#4A2E67_45%,#C9A040_100%)] transition-opacity duration-300 motion-reduce:transition-none',
          scrolled ? 'opacity-100' : 'opacity-0',
        )}
        style={{ transform: `scaleX(${scrollProgress / 100})` }}
      />

    </header>
    {open && (
      <MobileDrawer
        open={open}
        pathname={pathname}
        expandedKey={mobileExpanded}
        onClose={() => setOpen(false)}
        onToggleSection={handleMobileToggle}
      />
    )}
    </>
  );
}
