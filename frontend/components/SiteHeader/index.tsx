'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useEffect, useRef, useState } from 'react';
import { Menu, X, ExternalLink, ArrowRight, ChevronDown } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { PssfpLogo } from '@pssfp/ui';
import { cn } from '../../lib/cn';
import { ThemeToggle } from '../ThemeToggle';

interface NavLinkChild {
  href: string;
  key: string;
}

interface NavLink {
  href: string;
  key: string;
  children?: NavLinkChild[];
}

const NAV_LINKS: readonly NavLink[] = [
  { href: '/', key: 'home' },
  {
    href: '/a-propos',
    key: 'apropos',
    children: [
      { href: '/a-propos/mot-president', key: 'aproposMotPresident' },
      { href: '/a-propos/presentation', key: 'aproposPresentation' },
      { href: '/a-propos/comite-pilotage', key: 'aproposComitePilotage' },
      { href: '/a-propos/organigramme', key: 'aproposOrganigramme' },
      { href: '/a-propos/convention-tripartite', key: 'aproposConvention' },
      { href: '/a-propos/histoire', key: 'aproposHistoire' },
      { href: '/a-propos/infrastructure', key: 'aproposInfrastructure' },
      { href: '/a-propos/partenaires', key: 'aproposPartenaires' },
      { href: '/a-propos/conformite-cames', key: 'aproposCames' },
    ],
  },
  {
    href: '/formations',
    key: 'formations',
    children: [
      { href: '/formations/master', key: 'formationsMaster' },
      { href: '/formations/formation-continue', key: 'formationsContinue' },
      { href: '/formations/certifications', key: 'formationsCertifications' },
      { href: '/formations/seminaires', key: 'formationsSeminaires' },
      { href: '/formations/admission', key: 'formationsAdmission' },
      { href: '/formations/frais-de-scolarite', key: 'formationsFrais' },
    ],
  },
  { href: '/vie-academique', key: 'vie' },
  { href: '/actualites', key: 'actualites' },
  { href: '/contact', key: 'contact' },
] as const;

export function SiteHeader(): JSX.Element {
  const t = useTranslations('nav');
  const pathname = usePathname();
  const [open, setOpen] = useState(false);
  const [scrolled, setScrolled] = useState(false);
  const [openSubmenu, setOpenSubmenu] = useState<string | null>(null);
  const submenuTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const [mobileExpanded, setMobileExpanded] = useState<string | null>(null);

  useEffect(() => {
    const onScroll = (): void => setScrolled(window.scrollY > 8);
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

  return (
    <header
      data-testid="site-header"
      className={cn(
        'sticky top-0 z-40 w-full transition-all duration-300 ease-pssfp-out-expo',
        scrolled
          ? 'border-b border-[#EDE7F6] bg-white/80 shadow-pssfp-soft backdrop-blur-2xl dark:border-[#3A2A55] dark:bg-[#14091F]/85'
          : 'border-b border-transparent bg-white/95 backdrop-blur-2xs dark:bg-[#14091F]/80',
      )}
    >
      <a
        href="#main"
        className="sr-only focus:not-sr-only focus:absolute focus:left-3 focus:top-2 focus:rounded-md focus:bg-[#6B2FA0] focus:px-3 focus:py-2 focus:text-sm focus:text-white"
      >
        {t('skip')}
      </a>
      <div className="mx-auto flex max-w-7xl items-center justify-between gap-4 px-6 py-3 md:py-4">
        <Link
          href="/"
          aria-label="PSSFP — Accueil"
          className="group flex items-center gap-3 rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2"
        >
          <span className="relative inline-flex transition-transform duration-300 ease-pssfp-out-expo group-hover:scale-105">
            <span
              aria-hidden="true"
              className="absolute inset-0 -z-10 rounded-full opacity-0 blur-xl transition-opacity duration-300 group-hover:opacity-60"
              style={{ background: 'radial-gradient(circle, #9B59B6 0%, transparent 70%)' }}
            />
            <PssfpLogo size={48} />
          </span>
          <span className="hidden font-heading text-base font-bold text-[#6B2FA0] dark:text-[#B084E8] sm:block">
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
                      'relative inline-flex h-10 items-center gap-1 rounded-md px-3 transition-colors duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2 dark:focus-visible:ring-[#E8C868]',
                      active
                        ? 'font-semibold text-[#6B2FA0] dark:text-[#B084E8]'
                        : 'text-[#333] hover:text-[#6B2FA0] dark:text-[#F5EFE3] dark:hover:text-[#B084E8]',
                    )}
                  >
                    {t(link.key)}
                    {hasChildren && (
                      <ChevronDown
                        size={14}
                        aria-hidden="true"
                        className={cn(
                          'transition-transform duration-200',
                          isSubmenuOpen && 'rotate-180',
                        )}
                      />
                    )}
                    {active && (
                      <span
                        aria-hidden="true"
                        className="absolute inset-x-2 bottom-1 h-0.5 rounded-full bg-gradient-violet-or"
                      />
                    )}
                  </Link>

                  {hasChildren && isSubmenuOpen && (
                    <div
                      role="menu"
                      aria-label={t('aproposMenuLabel')}
                      data-testid={`nav-submenu-${link.key}`}
                      className="absolute left-0 top-full z-50 mt-1 min-w-[280px] origin-top rounded-pssfp-card border border-[#EDE7F6] bg-white shadow-pssfp-elevated dark:border-[#3A2A55] dark:bg-[#1F0E2E]"
                      onMouseEnter={() => handleSubmenuOpen(link.key)}
                      onMouseLeave={handleSubmenuClose}
                    >
                      <ul className="py-2">
                        {link.children!.map((child) => {
                          const childActive = pathname === child.href;
                          return (
                            <li key={child.href} role="none">
                              <Link
                                href={child.href}
                                role="menuitem"
                                aria-current={childActive ? 'page' : undefined}
                                data-testid={`nav-submenu-item-${child.key}`}
                                className={cn(
                                  'block px-4 py-2.5 text-sm transition-colors',
                                  childActive
                                    ? 'bg-[#EDE7F6] font-semibold text-[#6B2FA0] dark:bg-[#3A2A55]/50 dark:text-[#B084E8]'
                                    : 'text-[#333] hover:bg-[#EDE7F6]/50 hover:text-[#6B2FA0] dark:text-[#F5EFE3] dark:hover:bg-[#3A2A55]/30 dark:hover:text-[#B084E8]',
                                )}
                              >
                                {t(child.key)}
                              </Link>
                            </li>
                          );
                        })}
                      </ul>
                    </div>
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
            className="inline-flex h-10 items-center gap-1.5 rounded-pssfp-button border border-[#EDE7F6] bg-white px-3.5 text-sm text-[#333] transition-all duration-200 hover:border-[#6B2FA0] hover:text-[#6B2FA0] hover:shadow-pssfp-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2 dark:border-[#3A2A55] dark:bg-[#1F0E2E] dark:text-[#F5EFE3] dark:hover:border-[#B084E8] dark:hover:text-[#B084E8]"
            data-testid="nav-library"
          >
            {t('library')}
            <ExternalLink size={14} aria-hidden="true" />
          </a>
          <a
            href={process.env.NEXT_PUBLIC_CANDIDATURE_URL ?? '#'}
            className="group relative inline-flex h-10 items-center gap-1.5 overflow-hidden rounded-pssfp-button bg-gradient-violet-or px-4 text-sm font-medium text-white shadow-pssfp-elevated transition-all duration-200 hover:-translate-y-0.5 hover:shadow-pssfp-floating focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C9A227] focus-visible:ring-offset-2"
            data-testid="nav-candidature"
          >
            <span
              aria-hidden="true"
              className="absolute inset-0 -translate-x-full bg-gradient-to-r from-transparent via-white/25 to-transparent transition-transform duration-700 group-hover:translate-x-full"
            />
            <span className="relative">{t('candidature')}</span>
            <ArrowRight
              size={14}
              aria-hidden="true"
              className="relative transition-transform duration-200 group-hover:translate-x-0.5"
            />
          </a>
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
            className="inline-flex h-10 w-10 items-center justify-center rounded-md border border-[#EDE7F6] text-[#333] transition-colors hover:border-[#6B2FA0] hover:text-[#6B2FA0] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2 dark:border-[#3A2A55] dark:text-[#F5EFE3] dark:hover:border-[#B084E8] dark:hover:text-[#B084E8]"
          >
            {open ? <X size={20} aria-hidden="true" /> : <Menu size={20} aria-hidden="true" />}
          </button>
        </div>
      </div>

      {open && (
        <nav
          id="mobile-menu"
          data-testid="mobile-menu"
          aria-label="Navigation mobile"
          className="border-t border-[#EDE7F6] bg-white/95 backdrop-blur-2xl dark:border-[#3A2A55] dark:bg-[#14091F]/95 lg:hidden"
        >
          <ul className="mx-auto max-w-7xl divide-y divide-[#EDE7F6] px-6 py-2 text-base dark:divide-[#3A2A55]">
            {NAV_LINKS.map((link) => {
              const hasChildren = (link.children ?? []).length > 0;
              const expanded = mobileExpanded === link.key;
              return (
                <li key={link.href}>
                  <div className="flex items-center justify-between">
                    <Link
                      href={link.href}
                      aria-current={isActive(link.href) ? 'page' : undefined}
                      className={cn(
                        'block flex-1 rounded px-2 py-3 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] dark:focus-visible:ring-[#E8C868]',
                        isActive(link.href)
                          ? 'font-semibold text-[#6B2FA0] dark:text-[#B084E8]'
                          : 'text-[#333] hover:text-[#6B2FA0] dark:text-[#F5EFE3] dark:hover:text-[#B084E8]',
                      )}
                    >
                      {t(link.key)}
                    </Link>
                    {hasChildren && (
                      <button
                        type="button"
                        onClick={() => setMobileExpanded(expanded ? null : link.key)}
                        aria-expanded={expanded}
                        aria-label={`Déplier ${t(link.key)}`}
                        data-testid={`nav-mobile-toggle-${link.key}`}
                        className="ml-2 inline-flex h-10 w-10 items-center justify-center rounded text-[#333] transition-colors hover:text-[#6B2FA0] dark:text-[#F5EFE3] dark:hover:text-[#B084E8]"
                      >
                        <ChevronDown
                          size={18}
                          aria-hidden="true"
                          className={cn(
                            'transition-transform duration-200',
                            expanded && 'rotate-180',
                          )}
                        />
                      </button>
                    )}
                  </div>
                  {hasChildren && expanded && (
                    <ul className="mb-2 ml-4 border-l border-[#EDE7F6] pl-3 dark:border-[#3A2A55]">
                      {link.children!.map((child) => (
                        <li key={child.href}>
                          <Link
                            href={child.href}
                            aria-current={pathname === child.href ? 'page' : undefined}
                            className="block rounded px-2 py-2 text-sm text-[#555] hover:text-[#6B2FA0] dark:text-[#B5A8C8] dark:hover:text-[#B084E8]"
                          >
                            {t(child.key)}
                          </Link>
                        </li>
                      ))}
                    </ul>
                  )}
                </li>
              );
            })}
            <li>
              <a
                href={process.env.NEXT_PUBLIC_LIBRARY_URL ?? '#'}
                className="flex items-center justify-between rounded px-2 py-3 text-[#333] hover:text-[#6B2FA0] dark:text-[#F5EFE3] dark:hover:text-[#B084E8]"
              >
                {t('library')}
                <ExternalLink size={14} aria-hidden="true" />
              </a>
            </li>
            <li>
              <a
                href={process.env.NEXT_PUBLIC_CANDIDATURE_URL ?? '#'}
                className="mt-2 flex items-center justify-between rounded-pssfp-button bg-gradient-violet-or px-3 py-3 font-medium text-white shadow-pssfp-elevated"
              >
                {t('candidature')}
                <ArrowRight size={14} aria-hidden="true" />
              </a>
            </li>
          </ul>
        </nav>
      )}
    </header>
  );
}
