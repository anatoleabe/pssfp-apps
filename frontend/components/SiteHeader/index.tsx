'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useEffect, useState } from 'react';
import { Menu, X, ExternalLink, ArrowRight } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { PssfpLogo } from '@pssfp/ui';
import { cn } from '../../lib/cn';

const NAV_LINKS = [
  { href: '/', key: 'home' },
  { href: '/pssfp', key: 'pssfp' },
  { href: '/formations', key: 'formations' },
  { href: '/vie-academique', key: 'vie' },
  { href: '/actualites', key: 'actualites' },
  { href: '/contact', key: 'contact' },
] as const;

export function SiteHeader(): JSX.Element {
  const t = useTranslations('nav');
  const pathname = usePathname();
  const [open, setOpen] = useState(false);
  const [scrolled, setScrolled] = useState(false);

  useEffect(() => {
    const onScroll = (): void => setScrolled(window.scrollY > 8);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  // Ferme le menu mobile sur changement de route.
  useEffect(() => setOpen(false), [pathname]);

  // Lock body scroll quand le menu mobile est ouvert.
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

  return (
    <header
      data-testid="site-header"
      className={cn(
        'sticky top-0 z-40 w-full transition-all duration-300 ease-pssfp-out-expo',
        scrolled
          ? 'border-b border-[#EDE7F6] bg-white/80 shadow-pssfp-soft backdrop-blur-2xl'
          : 'border-b border-transparent bg-white/95 backdrop-blur-2xs',
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
          <span className="hidden font-heading text-base font-bold text-[#6B2FA0] sm:block">
            PSSFP
          </span>
        </Link>

        <nav aria-label="Navigation principale" className="hidden lg:block">
          <ul className="flex items-center gap-1 text-sm">
            {NAV_LINKS.map((link) => {
              const active = isActive(link.href);
              return (
                <li key={link.href} className="relative">
                  <Link
                    href={link.href}
                    data-testid={`nav-${link.key}`}
                    aria-current={active ? 'page' : undefined}
                    className={cn(
                      'relative inline-flex h-10 items-center rounded-md px-3 transition-colors duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2',
                      active
                        ? 'font-semibold text-[#6B2FA0]'
                        : 'text-[#333] hover:text-[#6B2FA0]',
                    )}
                  >
                    {t(link.key)}
                    {active && (
                      <span
                        aria-hidden="true"
                        className="absolute inset-x-2 bottom-1 h-0.5 rounded-full bg-gradient-violet-or"
                      />
                    )}
                  </Link>
                </li>
              );
            })}
          </ul>
        </nav>

        <div className="hidden items-center gap-2 lg:flex">
          <a
            href={process.env.NEXT_PUBLIC_LIBRARY_URL ?? '#'}
            className="inline-flex h-10 items-center gap-1.5 rounded-pssfp-button border border-[#EDE7F6] bg-white px-3.5 text-sm text-[#333] transition-all duration-200 hover:border-[#6B2FA0] hover:text-[#6B2FA0] hover:shadow-pssfp-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2"
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

        <button
          type="button"
          onClick={() => setOpen((v) => !v)}
          aria-label={open ? t('menuClose') : t('menuOpen')}
          aria-expanded={open}
          aria-controls="mobile-menu"
          data-testid="nav-toggle"
          className="inline-flex h-10 w-10 items-center justify-center rounded-md border border-[#EDE7F6] text-[#333] transition-colors hover:border-[#6B2FA0] hover:text-[#6B2FA0] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2 lg:hidden"
        >
          {open ? <X size={20} aria-hidden="true" /> : <Menu size={20} aria-hidden="true" />}
        </button>
      </div>

      {open && (
        <nav
          id="mobile-menu"
          data-testid="mobile-menu"
          aria-label="Navigation mobile"
          className="border-t border-[#EDE7F6] bg-white/95 backdrop-blur-2xl lg:hidden"
        >
          <ul className="mx-auto max-w-7xl divide-y divide-[#EDE7F6] px-6 py-2 text-base">
            {NAV_LINKS.map((link) => (
              <li key={link.href}>
                <Link
                  href={link.href}
                  aria-current={isActive(link.href) ? 'page' : undefined}
                  className={cn(
                    'block rounded px-2 py-3 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0]',
                    isActive(link.href)
                      ? 'font-semibold text-[#6B2FA0]'
                      : 'text-[#333] hover:text-[#6B2FA0]',
                  )}
                >
                  {t(link.key)}
                </Link>
              </li>
            ))}
            <li>
              <a
                href={process.env.NEXT_PUBLIC_LIBRARY_URL ?? '#'}
                className="flex items-center justify-between rounded px-2 py-3 text-[#333] hover:text-[#6B2FA0]"
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
