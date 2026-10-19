import Link from 'next/link';
import { ChevronRight, Home } from 'lucide-react';
import { useTranslations } from 'next-intl';

export interface BreadcrumbItem {
  href?: string;
  label: string;
}

interface BreadcrumbsProps {
  items: BreadcrumbItem[];
  /** `dark` : sur le bandeau prune des pages intérieures (texte clair, anneau or). */
  tone?: 'light' | 'dark';
}

const TONES = {
  light: {
    nav: 'text-pssfp-graphite-light',
    link: 'hover:text-pssfp-prune focus-visible:ring-pssfp-prune',
    chevron: 'text-pssfp-lavande',
    current: 'text-pssfp-graphite',
  },
  /* Sur prune « deep » : blanc 80 % 9,6:1, blanc 15,5:1, lavande (chevrons décoratifs) 5,5:1. */
  dark: {
    nav: 'text-white/80',
    link: 'hover:text-white focus-visible:ring-pssfp-or focus-visible:ring-offset-pssfp-prune-deep',
    chevron: 'text-pssfp-lavande',
    current: 'text-white',
  },
} as const;

/** Fil d'Ariane : même API et même balisage que celui du site principal. */
export function Breadcrumbs({ items, tone = 'light' }: BreadcrumbsProps): JSX.Element {
  const t = useTranslations('breadcrumbs');
  const s = TONES[tone];
  return (
    <nav aria-label={t('label')} className={`font-ui text-sm ${s.nav}`}>
      <ol className="flex flex-wrap items-center gap-1.5">
        {items.map((item, index) => {
          const isFirst = index === 0;
          const isCurrent = index === items.length - 1;
          return (
            <li key={`${item.label}-${index}`} className="inline-flex min-w-0 items-center gap-1.5">
              {!isFirst && <ChevronRight size={14} strokeWidth={1.75} aria-hidden="true" className={`shrink-0 ${s.chevron}`} />}
              {item.href && !isCurrent ? (
                <Link
                  href={item.href}
                  className={`inline-flex min-h-6 items-center gap-1.5 rounded-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 ${s.link}`}
                >
                  {isFirst && <Home size={14} strokeWidth={1.75} aria-hidden="true" />}
                  <span>{item.label}</span>
                </Link>
              ) : (
                <span aria-current={isCurrent ? 'page' : undefined} data-testid={isCurrent ? 'breadcrumb-current' : undefined} className={`truncate font-medium ${s.current}`}>
                  {item.label}
                </span>
              )}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}
