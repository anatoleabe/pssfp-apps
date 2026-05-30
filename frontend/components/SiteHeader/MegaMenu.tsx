'use client';

import Link from 'next/link';
import { ArrowRight } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { cn } from '../../lib/cn';
import type { NavLink } from './nav-config';

interface MegaMenuProps {
  link: NavLink;
  pathname: string;
  onItemFocus?: () => void;
  onItemBlur?: () => void;
}

export function MegaMenu({ link, pathname, onItemFocus, onItemBlur }: MegaMenuProps): JSX.Element {
  const t = useTranslations('nav');
  const children = link.children ?? [];
  const labelKey = link.menuLabelKey ?? `${link.key}MenuLabel`;

  return (
    <div
      role="menu"
      tabIndex={-1}
      aria-label={t(labelKey)}
      data-testid={`nav-submenu-${link.key}`}
      className="absolute left-1/2 top-full z-50 mt-2 w-[min(720px,90vw)] -translate-x-1/2 origin-top animate-pssfp-fade-up rounded-pssfp-card border border-[#C9A040]/35 bg-white/85 p-3 shadow-pssfp-floating backdrop-blur-2xl backdrop-saturate-150 supports-[backdrop-filter]:bg-white/70 motion-reduce:animate-none dark:border-[#C9A040]/25 dark:bg-[#2D1454]/80 dark:supports-[backdrop-filter]:bg-[#2D1454]/65"
      onFocus={onItemFocus}
      onBlur={onItemBlur}
    >
      <ul className="grid grid-cols-1 gap-1 sm:grid-cols-2">
        {children.map((child) => {
          const Icon = child.icon;
          const childActive = pathname === child.href;
          return (
            <li key={child.href} role="none">
              <Link
                href={child.href}
                role="menuitem"
                aria-current={childActive ? 'page' : undefined}
                data-testid={`nav-submenu-item-${child.key}`}
                className={cn(
                  'group flex items-start gap-3 rounded-pssfp-button px-3 py-3 transition-all duration-200',
                  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2',
                  'dark:focus-visible:ring-[#E5C788]',
                  childActive
                    ? 'bg-[#EFE9DF] dark:bg-[#3A2F48]/60'
                    : 'hover:bg-[#EFE9DF]/60 dark:hover:bg-[#3A2F48]/40',
                )}
              >
                <span
                  aria-hidden="true"
                  className={cn(
                    'mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-pssfp-button transition-all duration-200',
                    childActive
                      ? 'bg-gradient-prune-or text-white shadow-pssfp-glow-or'
                      : 'bg-[#EFE9DF] text-[#14101A] group-hover:bg-gradient-petrole-or group-hover:text-white group-hover:shadow-pssfp-soft dark:bg-[#3A2F48]/40 dark:text-[#E5C788]',
                  )}
                >
                  <Icon size={18} strokeWidth={1.75} />
                </span>
                <span className="flex min-w-0 flex-col">
                  <span
                    className={cn(
                      'truncate text-sm font-semibold',
                      childActive
                        ? 'text-[#4A2E67] dark:text-[#B084E8]'
                        : 'text-[#14101A] group-hover:text-[#0F3A4A] dark:text-[#F0E8D8] dark:group-hover:text-[#7FB0C4]',
                    )}
                  >
                    {t(child.key)}
                  </span>
                  <span className="mt-0.5 line-clamp-2 text-xs leading-relaxed text-[#6B6B6B] dark:text-[#B5A8C8]">
                    {t(child.descriptionKey)}
                  </span>
                </span>
              </Link>
            </li>
          );
        })}
      </ul>

      <div className="mt-3 border-t border-[#D8C9A6] pt-3 dark:border-[#3A2F48]/70">
        <Link
          href={link.href}
          role="menuitem"
          data-testid={`nav-submenu-all-${link.key}`}
          className={cn(
            'group/all inline-flex items-center gap-1.5 rounded px-3 py-1.5 text-xs font-medium uppercase tracking-wide',
            'text-[#4A2E67] hover:text-[#5C3A7E]',
            'dark:text-[#B084E8] dark:hover:text-[#E5C788]',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2',
          )}
        >
          {t('menuViewAll')}
          <ArrowRight
            size={14}
            aria-hidden="true"
            className="transition-transform duration-200 group-hover/all:translate-x-0.5"
          />
        </Link>
      </div>
    </div>
  );
}
