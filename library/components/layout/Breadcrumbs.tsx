import Link from 'next/link';
import { ChevronRight, Home } from 'lucide-react';
import { useTranslations } from 'next-intl';

export interface BreadcrumbItem {
  href?: string;
  label: string;
}

interface BreadcrumbsProps {
  items: BreadcrumbItem[];
}

/** Fil d'Ariane : même API et même balisage que celui du site principal. */
export function Breadcrumbs({ items }: BreadcrumbsProps): JSX.Element {
  const t = useTranslations('breadcrumbs');
  return (
    <nav aria-label={t('label')} className="font-ui text-sm text-pssfp-graphite-light">
      <ol className="flex flex-wrap items-center gap-1.5">
        {items.map((item, index) => {
          const isFirst = index === 0;
          const isCurrent = index === items.length - 1;
          return (
            <li key={`${item.label}-${index}`} className="inline-flex min-w-0 items-center gap-1.5">
              {!isFirst && (
                <ChevronRight size={14} strokeWidth={1.75} aria-hidden="true" className="shrink-0 text-pssfp-lavande" />
              )}
              {item.href && !isCurrent ? (
                <Link
                  href={item.href}
                  className="inline-flex items-center gap-1.5 rounded-sm transition-colors hover:text-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2"
                >
                  {isFirst && <Home size={14} strokeWidth={1.75} aria-hidden="true" />}
                  <span>{item.label}</span>
                </Link>
              ) : (
                <span
                  aria-current={isCurrent ? 'page' : undefined}
                  data-testid={isCurrent ? 'breadcrumb-current' : undefined}
                  className="truncate font-medium text-pssfp-graphite"
                >
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
