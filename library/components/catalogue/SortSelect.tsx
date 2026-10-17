import { useTranslations } from 'next-intl';
import { SORT_KEYS, type SortKey } from '@/lib/api/types';

const DEFAULT_SORT: SortKey = 'recent';

interface SortSelectProps {
  /** Chemin de la page (sans paramètres). */
  action: string;
  value: SortKey | undefined;
  /** Filtres courants, renvoyés tels quels ; la page n'en fait pas partie (retour en page 1). */
  hidden: Record<string, string>;
}

/**
 * Formulaire GET « Trier par » + bouton « Trier » toujours visible.
 * Pas de soumission au changement du `select` (WCAG 3.2.2 « Lors de la saisie » : choisir une
 * option ne doit pas, à lui seul, recharger la liste) ; fonctionne sans JavaScript.
 */
export function SortSelect({ action, value, hidden }: SortSelectProps): JSX.Element {
  const t = useTranslations('sort');
  return (
    <form action={action} method="get" data-testid="sort-form" className="flex items-center gap-2">
      {Object.entries(hidden).map(([name, v]) => (
        <input key={name} type="hidden" name={name} value={v} />
      ))}
      <label htmlFor="sort-select" className="shrink-0 font-ui text-sm text-pssfp-graphite-light">
        {t('label')}
      </label>
      {/* `key` : remonté à chaque changement d'URL, le champ reflète toujours le tri appliqué. */}
      <select
        key={value ?? DEFAULT_SORT}
        id="sort-select"
        name="tri"
        defaultValue={value ?? DEFAULT_SORT}
        className="h-10 min-w-0 rounded-pssfp-button border border-pssfp-lavande/60 bg-white pl-3 pr-8 font-ui text-sm font-medium text-pssfp-prune transition-colors hover:border-pssfp-prune/50 focus-visible:border-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune/30"
      >
        {SORT_KEYS.map((key) => (
          <option key={key} value={key}>
            {t(key)}
          </option>
        ))}
      </select>
      <button
        type="submit"
        className="h-10 shrink-0 rounded-pssfp-button border border-pssfp-prune/30 bg-white px-4 font-ui text-sm font-medium text-pssfp-prune transition-colors hover:border-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-or focus-visible:ring-offset-2 active:bg-pssfp-ivoire"
      >
        {t('submit')}
      </button>
    </form>
  );
}
