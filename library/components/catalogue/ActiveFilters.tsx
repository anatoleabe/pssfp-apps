import Link from 'next/link';
import { X } from 'lucide-react';
import { useTranslations } from 'next-intl';
import type { CatalogueFilters, DocumentType, MotCle } from '@/lib/api/types';
import { activeFilterKeys, collectionPageHref, collectionResetHref, withoutFilter, type FilterKey } from '@/lib/collections';

interface ActiveFiltersProps {
  type: DocumentType;
  filters: CatalogueFilters;
  motsCles: MotCle[];
}

const CHIP =
  'inline-flex h-8 items-center gap-1.5 rounded-full border border-pssfp-prune/60 bg-pssfp-lavande/15 pl-3 pr-2 font-ui text-sm text-pssfp-prune-dark transition-colors hover:border-pssfp-prune hover:bg-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2';

/**
 * Puces des filtres actifs : chacune est un lien qui retire ce seul filtre (tri conservé, retour en page 1).
 * Une spécialité sans intitulé connu (messages `specialites`) ou un mot-clé absent du thésaurus (ou thésaurus
 * indisponible) n'a pas de puce : jamais de slug brut affiché ; le filtre reste retirable par « Retirer les
 * filtres » ou « Réinitialiser ».
 */
export function ActiveFilters({ type, filters, motsCles }: ActiveFiltersProps): JSX.Element | null {
  const t = useTranslations('activeFilters');
  const tFilters = useTranslations('filters');
  const tAccess = useTranslations('access');
  const tCatalogue = useTranslations('catalogue');
  const tSpecialites = useTranslations('specialites');
  const keys = activeFilterKeys(filters);

  /** Valeur affichée de la puce, ou null pour ne pas en afficher. */
  const valueOf = (key: FilterKey): string | null => {
    if (key === 'acces' && filters.acces) return tAccess(filters.acces);
    if (key === 'langue' && filters.langue) return tFilters(`languages.${filters.langue}`);
    if (key === 'mot_cle') return motsCles.find((m) => m.slug === filters.mot_cle)?.label ?? null;
    if (key === 'specialite') return filters.specialite && tSpecialites.has(filters.specialite) ? tSpecialites(filters.specialite) : null;
    return String(filters[key]);
  };
  const chips = keys.flatMap((key) => {
    const value = valueOf(key);
    return value === null ? [] : [{ key, label: t(key, { value }) }];
  });
  if (chips.length === 0 && keys.length < 2) return null;

  return (
    <div data-testid="active-filters" className="flex flex-wrap items-center gap-2">
      {chips.length > 0 && (
        <ul aria-label={t('label')} className="flex flex-wrap items-center gap-2">
          {chips.map(({ key, label }) => (
            <li key={key}>
              <Link href={collectionPageHref(type, withoutFilter(filters, key))} scroll={false} aria-label={t('remove', { label })} className={CHIP}>
                {label}
                <X size={14} strokeWidth={2} aria-hidden="true" />
              </Link>
            </li>
          ))}
        </ul>
      )}
      {keys.length > 1 && (
        <Link
          href={collectionResetHref(type, filters)}
          scroll={false}
          className="ml-1 rounded-sm font-ui text-sm font-medium text-pssfp-prune underline decoration-pssfp-lavande underline-offset-4 transition-colors hover:decoration-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2"
        >
          {tCatalogue('resetFilters')}
        </Link>
      )}
    </div>
  );
}
