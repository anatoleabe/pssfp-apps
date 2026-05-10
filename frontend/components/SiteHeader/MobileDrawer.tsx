'use client';

import Link from 'next/link';
import { useEffect, useRef, useState } from 'react';
import { ArrowRight, ChevronDown, ExternalLink } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { cn } from '../../lib/cn';
import { NAV_LINKS } from './nav-config';

interface MobileDrawerProps {
  open: boolean;
  pathname: string;
  expandedKey: string | null;
  onClose: () => void;
  onToggleSection: (key: string) => void;
}

export function MobileDrawer({
  open,
  pathname,
  expandedKey,
  onClose,
  onToggleSection,
}: MobileDrawerProps): JSX.Element {
  const t = useTranslations('nav');
  const panelRef = useRef<HTMLDivElement>(null);
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    const raf = window.requestAnimationFrame(() => setVisible(true));
    return () => window.cancelAnimationFrame(raf);
  }, []);

  useEffect(() => {
    if (!open) return undefined;
    const onKey = (e: KeyboardEvent): void => {
      if (e.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [open, onClose]);

  const isActive = (href: string): boolean => {
    if (href === '/') return pathname === '/';
    return pathname === href || pathname.startsWith(`${href}/`);
  };

  return (
    <div
      aria-hidden={!open}
      className={cn(
        'fixed inset-0 z-30 lg:hidden',
        open ? 'pointer-events-auto' : 'pointer-events-none',
      )}
    >
      <div
        onClick={onClose}
        aria-hidden="true"
        className={cn(
          'absolute inset-0 bg-[#1F1A28]/60 backdrop-blur-sm transition-opacity duration-300',
          visible && open ? 'opacity-100' : 'opacity-0',
        )}
      />

      <aside
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-label={t('menuOpen')}
        id="mobile-menu"
        data-testid="mobile-menu"
        className={cn(
          'absolute right-0 top-0 flex h-full w-[min(420px,90vw)] flex-col pt-[72px]',
          'border-l border-[#E4D8B7] bg-white shadow-pssfp-floating',
          'dark:border-[#3A2F48] dark:bg-[#14101A]',
          'transition-transform duration-300 ease-pssfp-out-expo',
          visible && open ? 'translate-x-0' : 'translate-x-full',
        )}
      >
        <nav
          aria-label="Navigation mobile"
          className="flex-1 overflow-y-auto px-5 py-4"
        >
          <ul className="flex flex-col gap-1 text-base">
            {NAV_LINKS.map((link) => {
              const hasChildren = (link.children ?? []).length > 0;
              const expanded = expandedKey === link.key;
              const active = isActive(link.href);
              return (
                <li key={link.href} className="rounded-pssfp-button">
                  <div className="flex items-center gap-1">
                    <Link
                      href={link.href}
                      onClick={onClose}
                      aria-current={active ? 'page' : undefined}
                      className={cn(
                        'flex-1 rounded-pssfp-button px-3 py-3 transition-colors',
                        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] dark:focus-visible:ring-[#E8C868]',
                        active
                          ? 'bg-[#EFE6CE] font-semibold text-[#0E4D3F] dark:bg-[#3A2F48]/50 dark:text-[#6FBDA0]'
                          : 'text-[#2A2230] hover:bg-[#F8F1DF] hover:text-[#0E4D3F] dark:text-[#F0E8D8] dark:hover:bg-[#3A2F48]/30 dark:hover:text-[#6FBDA0]',
                      )}
                    >
                      {t(link.key)}
                    </Link>
                    {hasChildren && (
                      <button
                        type="button"
                        onClick={() => onToggleSection(link.key)}
                        aria-expanded={expanded}
                        aria-label={`Déplier ${t(link.key)}`}
                        data-testid={`nav-mobile-toggle-${link.key}`}
                        className="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-pssfp-button text-[#6B6378] transition-colors hover:bg-[#F8F1DF] hover:text-[#0E4D3F] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0E4D3F] dark:text-[#B5ACBF] dark:hover:bg-[#3A2F48]/40 dark:hover:text-[#6FBDA0]"
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
                  {hasChildren && (
                    <div
                      className={cn(
                        'grid transition-all duration-300 ease-pssfp-out-expo',
                        expanded ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0',
                      )}
                    >
                      <ul className="overflow-hidden border-l border-[#E4D8B7] pl-3 dark:border-[#3A2F48]">
                        {link.children!.map((child) => {
                          const Icon = child.icon;
                          const childActive = pathname === child.href;
                          return (
                            <li key={child.href}>
                              <Link
                                href={child.href}
                                onClick={onClose}
                                aria-current={childActive ? 'page' : undefined}
                                className={cn(
                                  'group flex items-center gap-2.5 rounded px-2 py-2.5 text-sm transition-colors',
                                  childActive
                                    ? 'font-semibold text-[#6B2FA0] dark:text-[#B084E8]'
                                    : 'text-[#555] hover:text-[#6B2FA0] dark:text-[#B5A8C8] dark:hover:text-[#B084E8]',
                                )}
                              >
                                <Icon
                                  size={16}
                                  strokeWidth={1.75}
                                  aria-hidden="true"
                                  className={cn(
                                    'shrink-0 transition-colors',
                                    childActive
                                      ? 'text-[#6B2FA0] dark:text-[#B084E8]'
                                      : 'text-[#9B59B6]/70 group-hover:text-[#6B2FA0] dark:text-[#B084E8]/70',
                                  )}
                                />
                                <span className="truncate">{t(child.key)}</span>
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

        <div className="border-t border-[#E4D8B7] bg-[#F4ECDC] px-5 py-4 dark:border-[#3A2F48] dark:bg-[#1F1A28]">
          <a
            href={process.env.NEXT_PUBLIC_LIBRARY_URL ?? '#'}
            onClick={onClose}
            className="mb-2 flex items-center justify-between rounded-pssfp-button border border-[#E4D8B7] bg-white px-4 py-3 text-sm text-[#333] transition-all hover:border-[#6B2FA0] hover:text-[#6B2FA0] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#6B2FA0] focus-visible:ring-offset-2 dark:border-[#3A2F48] dark:bg-[#14101A] dark:text-[#F5EFE3] dark:hover:border-[#B084E8] dark:hover:text-[#B084E8]"
          >
            <span className="font-medium">{t('library')}</span>
            <ExternalLink size={14} aria-hidden="true" />
          </a>
          <a
            href={process.env.NEXT_PUBLIC_CANDIDATURE_URL ?? '#'}
            onClick={onClose}
            className="group flex items-center justify-between rounded-pssfp-button bg-gradient-violet-or px-4 py-3 font-medium text-white shadow-pssfp-elevated transition-all hover:shadow-pssfp-floating focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C9A227] focus-visible:ring-offset-2"
          >
            {t('candidature')}
            <ArrowRight
              size={14}
              aria-hidden="true"
              className="transition-transform duration-200 group-hover:translate-x-0.5"
            />
          </a>
        </div>
      </aside>
    </div>
  );
}
