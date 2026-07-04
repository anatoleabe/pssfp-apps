import Link from 'next/link';
import { ChevronRight, Home } from 'lucide-react';

interface BreadcrumbItem {
  href?: string;
  label: string;
}

interface BreadcrumbsProps {
  items: BreadcrumbItem[];
}

export function Breadcrumbs({ items }: BreadcrumbsProps): JSX.Element {
  return (
    <nav aria-label="Fil d'Ariane" className="text-sm text-[var(--pssfp-text-muted)]">
      <ol className="flex flex-wrap items-center gap-1.5">
        {items.map((item, index) => {
          const isFirst = index === 0;
          const isCurrent = index === items.length - 1;

          return (
            <li key={`${item.label}-${index}`} className="inline-flex min-w-0 items-center gap-1.5">
              {!isFirst && (
                <ChevronRight
                  size={14}
                  strokeWidth={1.75}
                  aria-hidden="true"
                  className="shrink-0 text-pssfp-or"
                />
              )}
              {item.href && !isCurrent ? (
                <Link
                  href={item.href}
                  className="inline-flex items-center gap-1.5 rounded-sm transition-colors hover:text-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2 dark:hover:text-[#B084E8]"
                >
                  {isFirst && <Home size={14} strokeWidth={1.75} aria-hidden="true" />}
                  <span>{item.label}</span>
                </Link>
              ) : (
                <span
                  aria-current={isCurrent ? 'page' : undefined}
                  data-testid={isCurrent ? 'breadcrumb-current' : undefined}
                  className="truncate font-medium text-[var(--pssfp-text)]"
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
