import Link from 'next/link';
import { X } from 'lucide-react';
import { useTranslations } from 'next-intl';
import type { CatalogueFilters, MotCle } from '@/lib/api/types';
import { activeFilterKeys, withoutFilter, type FilterKey } from '@/lib/collections';
import { filtersToSearchParams } from '@/lib/routes';
import { searchPageHref } from '@/lib/search';

interface SearchScopeProps {
  q: string;
  filters: CatalogueFilters;
  /** Intitulés des mots-clés (liste vide si indisponible : aucune puce de mot-clé). */
  motsCles: MotCle[];
}

const CHIP =
  'inline-flex min-h-8 items-center gap-1.5 rounded-full border border-pssfp-prune/25 bg-pssfp-lavande/15 py-1 pl-3 pr-2 font-ui text-sm text-pssfp-prune-dark transition-colors hover:border-pssfp-prune hover:bg-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-or focus-visible:ring-offset-2';

/**
 * Portée reportée sur une nouvelle requête : une puce par filtre appliqué, placée sous le champ.
 * Chaque puce est un lien qui retire ce filtre, garde `q` et revient en page 1. Même règle que
 * `ActiveFilters` : jamais de slug brut. La page ne reporte d'ailleurs ni spécialité ni mot-clé sans intitulé.
 */
export function SearchScope({ q, filters, motsCles }: SearchScopeProps): JSX.Element | null {
  const t = useTranslations('search.scope');
  const tActive = useTranslations('activeFilters');
  const tTypes = useTranslations('types');
  const tAccess = useTranslations('access');
  const tFilters = useTranslations('filters');
  const tSpecialites = useTranslations('specialites');

  const valueOf = (key: FilterKey): string | null => {
    if (key === 'acces' && filters.acces) return tAccess(filters.acces);
    if (key === 'langue' && filters.langue) return tFilters(`languages.${filters.langue}`);
    if (key === 'mot_cle') return motsCles.find((m) => m.slug === filters.mot_cle)?.label ?? null;
    if (key === 'specialite') return filters.specialite && tSpecialites.has(filters.specialite) ? tSpecialites(filters.specialite) : null;
    return String(filters[key]);
  };

  // Sans requête valable (invite), le lien garde les autres filtres sans ajouter de `q` vide.
  const hrefWithout = (rest: CatalogueFilters): string => (q ? searchPageHref(q, rest) : `/recherche?${filtersToSearchParams(rest).toString()}`);
  const chips: Array<{ key: string; label: string; href: string }> = [];
  if (filters.type) {
    const { type: _type, ...rest } = filters;
    chips.push({ key: 'type', label: t('type', { value: tTypes(`${filters.type}.other`) }), href: hrefWithout(rest) });
  }
  for (const key of activeFilterKeys(filters)) {
    const value = valueOf(key);
    if (value !== null) chips.push({ key, label: tActive(key, { value }), href: hrefWithout(withoutFilter(filters, key)) });
  }
  if (chips.length === 0) return null;

  return (
    <ul aria-label={t('label')} data-testid="search-scope" className="mt-3 flex flex-wrap gap-2">
      {chips.map((chip) => (
        <li key={chip.key}>
          <Link href={chip.href} aria-label={tActive('remove', { label: chip.label })} className={CHIP}>
            {chip.label}
            <X size={14} strokeWidth={2} aria-hidden="true" />
          </Link>
        </li>
      ))}
    </ul>
  );
}
