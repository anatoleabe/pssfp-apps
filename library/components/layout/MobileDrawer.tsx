'use client';

import Link from 'next/link';
import { useEffect, useRef, useState } from 'react';
import { ArrowUpRight, Search, X } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { cn } from '@pssfp/ui';
import { FOAD_URL, MAIN_SITE_URL, NAV_ITEMS, SEARCH_HREF, isNavActive } from './nav-config';

interface MobileDrawerProps {
  pathname: string;
  /** Échap, bouton Fermer, clic sur le fond : ferme et rend le focus au bouton du menu. */
  onClose: () => void;
  /** Clic sur un lien : ferme sans déplacer le focus (la navigation s'en charge). */
  onNavigate: () => void;
}

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])';

/** Tiroir modal : focus piégé, Échap et clic sur le fond ferment, défilement de la page verrouillé. */
export function MobileDrawer({ pathname, onClose, onNavigate }: MobileDrawerProps): JSX.Element {
  const t = useTranslations('nav');
  const panelRef = useRef<HTMLDivElement>(null);
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    const raf = window.requestAnimationFrame(() => setVisible(true));
    return () => window.cancelAnimationFrame(raf);
  }, []);

  useEffect(() => {
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.body.style.overflow = previousOverflow;
    };
  }, []);

  useEffect(() => {
    const panel = panelRef.current;
    panel?.querySelector<HTMLElement>(FOCUSABLE)?.focus();

    const onKey = (e: KeyboardEvent): void => {
      if (e.key === 'Escape') {
        e.preventDefault();
        onClose();
        return;
      }
      if (e.key !== 'Tab' || !panel) return;
      const items = Array.from(panel.querySelectorAll<HTMLElement>(FOCUSABLE));
      const first = items[0];
      const last = items[items.length - 1];
      if (!first || !last) return;
      const current = document.activeElement;
      if (e.shiftKey && (current === first || !panel.contains(current))) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && (current === last || !panel.contains(current))) {
        e.preventDefault();
        first.focus();
      }
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [onClose]);

  return (
    <div className="fixed inset-0 z-50 lg:hidden">
      <div
        aria-hidden="true"
        onClick={onClose}
        data-testid="mobile-menu-backdrop"
        className={cn(
          'absolute inset-0 bg-pssfp-prune-dark/60 backdrop-blur-sm transition-opacity duration-300',
          visible ? 'opacity-100' : 'opacity-0',
        )}
      />
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby="mobile-menu-title"
        id="mobile-menu"
        data-testid="mobile-menu"
        className={cn(
          'absolute right-0 top-0 flex h-full w-[min(380px,88vw)] flex-col bg-pssfp-ivoire shadow-pssfp-floating',
          'transition-transform duration-300 ease-pssfp-out-expo',
          visible ? 'translate-x-0' : 'translate-x-full',
        )}
      >
        <div className="flex items-center justify-between border-b border-pssfp-lavande/40 px-5 py-4">
          <h2
            id="mobile-menu-title"
            className="font-ui text-xs font-medium uppercase tracking-[0.18em] text-pssfp-bleu-petrole"
          >
            {t('menuTitle')}
          </h2>
          <button
            type="button"
            onClick={onClose}
            aria-label={t('menuClose')}
            className="inline-flex h-11 w-11 items-center justify-center rounded-pssfp-button text-pssfp-prune transition-colors hover:bg-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2 focus-visible:ring-offset-pssfp-ivoire"
          >
            <X size={20} aria-hidden="true" />
          </button>
        </div>

        <nav aria-label={t('mobileLabel')} className="flex-1 overflow-y-auto px-3 py-4">
          <ul className="flex flex-col gap-1">
            {NAV_ITEMS.map((item) => {
              const active = isNavActive(pathname, item.href);
              return (
                <li key={item.key}>
                  <Link
                    href={item.href}
                    onClick={onNavigate}
                    aria-current={active ? 'page' : undefined}
                    className={cn(
                      'flex items-center border-l-2 px-4 py-3 font-heading text-2xl font-semibold transition-colors',
                      'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2 focus-visible:ring-offset-pssfp-ivoire',
                      active
                        ? 'border-pssfp-prune text-pssfp-prune'
                        : 'border-transparent text-pssfp-graphite hover:border-pssfp-lavande hover:text-pssfp-prune',
                    )}
                  >
                    {t(item.key)}
                  </Link>
                </li>
              );
            })}
            <li className="mt-2 border-t border-pssfp-lavande/40 pt-3">
              <Link
                href={SEARCH_HREF}
                onClick={onNavigate}
                aria-current={isNavActive(pathname, SEARCH_HREF) ? 'page' : undefined}
                className={cn(
                  'flex items-center gap-3 rounded-pssfp-button px-4 py-3 font-ui text-base font-medium text-pssfp-prune transition-colors hover:bg-white',
                  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2 focus-visible:ring-offset-pssfp-ivoire',
                )}
              >
                <Search size={18} aria-hidden="true" />
                {t('search')}
              </Link>
            </li>
          </ul>
        </nav>

        <div className="flex flex-col gap-2 border-t border-pssfp-lavande/40 px-5 py-5">
          <a
            href={MAIN_SITE_URL}
            onClick={onNavigate}
            className="flex items-center justify-between rounded-pssfp-button bg-pssfp-prune px-4 py-3 font-ui text-sm font-medium text-white transition-colors hover:bg-pssfp-prune-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2 focus-visible:ring-offset-pssfp-ivoire"
          >
            {t('mainSite')}
            <ArrowUpRight size={16} aria-hidden="true" />
          </a>
          <a
            href={FOAD_URL}
            onClick={onNavigate}
            className="flex items-center justify-between rounded-pssfp-button border border-pssfp-prune/30 bg-white px-4 py-3 font-ui text-sm font-medium text-pssfp-prune transition-colors hover:border-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2 focus-visible:ring-offset-pssfp-ivoire"
          >
            {t('foad')}
            <ArrowUpRight size={16} aria-hidden="true" />
          </a>
        </div>
      </div>
    </div>
  );
}
