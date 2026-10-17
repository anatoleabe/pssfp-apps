import Link from 'next/link';
import { Check } from 'lucide-react';
import { useFormatter, useTranslations } from 'next-intl';
import { ACCESS_MODES, DOCUMENT_TYPES, type CatalogueFilters, type SearchResponse } from '@/lib/api/types';
import { facetHref, type FacetKey } from '@/lib/search';

type Facets = SearchResponse['meta']['facets'];

interface FacetListProps {
  q: string;
  filters: CatalogueFilters;
  facets: Facets;
  /** Préfixe des identifiants : la liste est rendue deux fois (tiroir mobile, colonne ≥ lg). */
  idPrefix: string;
  /** `h3` sous le titre h2 « Affiner » de la colonne ; `p` dans le tiroir mobile (aucun h2 au-dessus). */
  titleAs: 'h3' | 'p';
}

interface FacetItem {
  value: string;
  label: string;
  count: number;
  active: boolean;
}

interface FacetGroup {
  key: FacetKey;
  title: string;
  items: FacetItem[];
}

const LEGEND = 'font-ui text-xs font-semibold uppercase tracking-[0.16em] text-pssfp-prune';
const ITEM =
  'group flex min-h-10 items-center gap-3 rounded-pssfp-button px-2 py-1.5 font-ui text-sm transition-colors hover:bg-pssfp-lavande/15 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-or focus-visible:ring-offset-2';

/**
 * Valeurs d'une facette : celles que l'API compte (> 0), dans l'ordre canonique, plus la valeur
 * appliquée même absente des compteurs (elle doit rester retirable).
 */
function facetItems(order: readonly string[], counts: Partial<Record<string, number>> | undefined, applied: string | undefined, labelOf: (v: string) => string): FacetItem[] {
  return order
    .filter((value) => (counts?.[value] ?? 0) > 0 || value === applied)
    .map((value) => ({ value, label: labelOf(value), count: counts?.[value] ?? 0, active: value === applied }));
}

/**
 * Facettes type et accès : des liens (et non des cases à cocher) qui ajoutent ou retirent le filtre.
 * Aucun changement de contexte « à la saisie » ; l'URL reste partageable et le bouton Retour la restaure.
 */
/** Vrai si au moins une facette a une valeur à afficher (comptée ou appliquée). */
export function hasFacetValues(facets: Facets, filters: CatalogueFilters): boolean {
  const counted = (c: Partial<Record<string, number>> | undefined): boolean => Object.values(c ?? {}).some((n) => (n ?? 0) > 0);
  return counted(facets.type) || counted(facets.access_mode) || filters.type !== undefined || filters.acces !== undefined;
}

export function FacetList({ q, filters, facets, idPrefix, titleAs: Title }: FacetListProps): JSX.Element | null {
  const t = useTranslations('search.facets');
  const tTypes = useTranslations('types');
  const tAccess = useTranslations('access');
  const format = useFormatter();

  const all: FacetGroup[] = [
    { key: 'type', title: t('type'), items: facetItems(DOCUMENT_TYPES, facets.type, filters.type, (v) => tTypes(`${v}.other`)) },
    { key: 'acces', title: t('acces'), items: facetItems(ACCESS_MODES, facets.access_mode, filters.acces, (v) => tAccess(v)) },
  ];
  const groups = all.filter((group) => group.items.length > 0);
  if (groups.length === 0) return null;

  return (
    <div className="flex flex-col gap-7">
      {groups.map((group) => (
        <div key={group.key} role="group" aria-labelledby={`${idPrefix}-facet-${group.key}`}>
          <Title id={`${idPrefix}-facet-${group.key}`} className={LEGEND}>
            {group.title}
          </Title>
          <ul className="-mx-2 mt-3 flex flex-col gap-0.5">
            {group.items.map((item) => (
              <li key={item.value}>
                <Link
                  href={facetHref(q, filters, group.key, item.value)}
                  // Pas de remontée en haut de page : le focus reste sur la facette activée.
                  scroll={false}
                  aria-current={item.active ? 'true' : undefined}
                  aria-label={t(item.active ? 'linkActive' : 'link', { label: item.label, count: item.count })}
                  className={`${ITEM} ${item.active ? 'font-semibold text-pssfp-prune-dark' : 'text-pssfp-graphite'}`}
                >
                  {/* Case visuelle : l'état passe aussi par la coche, la graisse et aria-current. */}
                  <span
                    aria-hidden="true"
                    className={`inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-[0.25rem] border transition-colors forced-colors:border-[CanvasText] ${
                      item.active ? 'border-pssfp-prune bg-pssfp-prune text-white' : 'border-pssfp-lavande bg-white group-hover:border-pssfp-prune/60'
                    }`}
                  >
                    {item.active && <Check size={12} strokeWidth={3} />}
                  </span>
                  <span className="min-w-0 flex-1">{item.label}</span>
                  <span aria-hidden="true" className="shrink-0 text-xs text-pssfp-graphite-light [font-variant-numeric:tabular-nums]">
                    {format.number(item.count)}
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        </div>
      ))}
    </div>
  );
}
