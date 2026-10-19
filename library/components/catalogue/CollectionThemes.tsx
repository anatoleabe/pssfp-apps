import Link from 'next/link';
import { Check, X } from 'lucide-react';
import { useFormatter, useTranslations } from 'next-intl';
import type { CatalogueFilters, DocumentType, MotCle } from '@/lib/api/types';
import { collectionPageHref, withoutFilter } from '@/lib/collections';
import { themesHref } from '@/lib/routes';
import { collectionThemes } from '@/lib/themes';

interface CollectionThemesProps {
  type: DocumentType;
  filters: CatalogueFilters;
  /** Mots-clés comptés pour ce type (`getMotsCles({ type })`) ; vide si indisponible. */
  motsCles: MotCle[];
}

const CHIP =
  'inline-flex min-h-9 items-center gap-2 rounded-full border px-3.5 font-ui text-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2';
/** Contour au repos prune 60 % : ≥ 3:1 sur blanc et ivoire (WCAG 1.4.11). */
const IDLE = `${CHIP} border-pssfp-prune/60 bg-white text-pssfp-prune-dark hover:border-pssfp-prune hover:bg-pssfp-mist`;
const ACTIVE = `${CHIP} border-pssfp-prune bg-pssfp-prune font-semibold text-white hover:bg-pssfp-prune-dark`;

/**
 * « Thèmes de la collection » : mots-clés ayant des documents de ce type, par nombre décroissant
 * (12 au plus). Chaque puce filtre la collection (`mot_cle`, autres filtres et tri conservés, retour
 * en page 1) ; la puce appliquée retire le filtre. Masqué sans comptes ou sans thème.
 */
export function CollectionThemes({ type, filters, motsCles }: CollectionThemesProps): JSX.Element | null {
  const t = useTranslations('collectionThemes');
  const format = useFormatter();
  const themes = collectionThemes(motsCles);
  if (themes.length === 0) return null;

  return (
    <nav aria-labelledby="collection-themes-title" data-testid="collection-themes" className="mb-8">
      <div className="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-2">
        <h2 id="collection-themes-title" className="font-ui text-xs font-semibold uppercase tracking-[0.16em] text-pssfp-prune-light">
          {t('title')}
        </h2>
        <Link href={themesHref} className="rounded-sm font-ui text-sm font-semibold text-pssfp-prune underline decoration-pssfp-lavande underline-offset-4 transition-colors hover:decoration-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2">
          {t('all')}
        </Link>
      </div>
      <ul className="mt-3 flex flex-wrap gap-2">
        {themes.map((m) => {
          const active = filters.mot_cle === m.slug;
          const href = active ? collectionPageHref(type, withoutFilter(filters, 'mot_cle')) : collectionPageHref(type, { ...filters, mot_cle: m.slug });
          return (
            <li key={m.slug}>
              <Link
                href={href}
                scroll={false}
                aria-current={active ? 'true' : undefined}
                aria-label={t(active ? 'chipActive' : 'chip', { label: m.label, count: m.documents_count })}
                className={active ? ACTIVE : IDLE}
              >
                {active && <Check size={14} strokeWidth={2.5} aria-hidden="true" />}
                {m.label}
                <span className={`text-xs [font-variant-numeric:lining-nums_tabular-nums] ${active ? 'text-white/85' : 'text-pssfp-graphite-light'}`}>{format.number(m.documents_count)}</span>
                {active && <X size={14} strokeWidth={2} aria-hidden="true" />}
              </Link>
            </li>
          );
        })}
      </ul>
    </nav>
  );
}
