import Link from 'next/link';
import { ChevronLeft, ChevronRight, Ellipsis } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { paginationItems } from '@/lib/pagination';

interface PaginationProps {
  page: number;
  lastPage: number;
  hrefFor: (page: number) => string;
  /** Faux : pas de remontée en haut de page (la page déplace elle-même le focus vers ses résultats). */
  scroll?: boolean;
}

const ITEM =
  'inline-flex h-10 min-w-10 items-center justify-center gap-1.5 rounded-pssfp-button px-3 font-ui text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2';
const IDLE = `${ITEM} border border-pssfp-lavande/40 bg-white text-pssfp-prune hover:border-pssfp-prune`;
const CURRENT = `${ITEM} bg-pssfp-prune text-white`;

/** Liens (et non boutons) : la pagination reste utilisable sans JavaScript. */
export function Pagination({ page, lastPage, hrefFor, scroll = true }: PaginationProps): JSX.Element | null {
  const t = useTranslations('pagination');
  const items = paginationItems(page, lastPage);
  const last = items[items.length - 1] as number;
  if (last <= 1) return null;
  const current = Math.min(Math.max(1, Math.floor(page) || 1), last);

  return (
    <nav aria-label={t('label')} data-pagination="" className="mt-10">
      {/* Pas de <li> vide aux bords : « précédente » et « suivante » n'existent que s'il y a une page où aller. */}
      <ul className="flex flex-wrap items-center justify-center gap-1.5">
        {current > 1 && (
          <li>
            <Link href={hrefFor(current - 1)} rel="prev" scroll={scroll} className={IDLE}>
              <ChevronLeft size={16} aria-hidden="true" />
              <span className="sr-only sm:not-sr-only">{t('previous')}</span>
            </Link>
          </li>
        )}
        {items.map((item, i) =>
          item === 'ellipsis' ? (
            <li key={`ellipsis-${i}`} aria-hidden="true" className="px-1 text-pssfp-graphite-light">
              <Ellipsis size={16} />
            </li>
          ) : (
            <li key={item}>
              <Link
                href={hrefFor(item)}
                scroll={scroll}
                aria-current={item === current ? 'page' : undefined}
                aria-label={item === current ? t('current', { page: item, total: last }) : t('page', { page: item })}
                className={item === current ? CURRENT : IDLE}
              >
                {item}
              </Link>
            </li>
          ),
        )}
        {current < last && (
          <li>
            <Link href={hrefFor(current + 1)} rel="next" scroll={scroll} className={IDLE}>
              <span className="sr-only sm:not-sr-only">{t('next')}</span>
              <ChevronRight size={16} aria-hidden="true" />
            </Link>
          </li>
        )}
      </ul>
    </nav>
  );
}
