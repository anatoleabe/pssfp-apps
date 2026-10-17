'use client';

import { useRouter } from 'next/navigation';
import { useEffect, useState, type ChangeEvent } from 'react';
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
 * Formulaire GET « Trier par » + bouton « Trier » : fonctionne sans JavaScript.
 * Amélioration : navigation dès le changement, sans rechargement complet (le focus reste
 * sur la liste), et bouton masqué visuellement (il réapparaît s'il reçoit le focus).
 */
export function SortSelect({ action, value, hidden }: SortSelectProps): JSX.Element {
  const t = useTranslations('sort');
  const router = useRouter();
  const [enhanced, setEnhanced] = useState(false);
  const [current, setCurrent] = useState<SortKey>(value ?? DEFAULT_SORT);

  useEffect(() => setEnhanced(true), []);
  // Retour arrière ou lien externe : la liste suit l'URL.
  useEffect(() => setCurrent(value ?? DEFAULT_SORT), [value]);

  const onChange = (event: ChangeEvent<HTMLSelectElement>): void => {
    const next = event.target.value as SortKey;
    setCurrent(next);
    const qs = new URLSearchParams(hidden);
    if (next !== DEFAULT_SORT) qs.set('tri', next);
    const s = qs.toString();
    router.push(`${action}${s ? `?${s}` : ''}`, { scroll: false });
  };

  return (
    <form action={action} method="get" data-testid="sort-form" className="flex items-center gap-2">
      {Object.entries(hidden).map(([name, v]) => (
        <input key={name} type="hidden" name={name} value={v} />
      ))}
      <label htmlFor="sort-select" className="shrink-0 font-ui text-sm text-pssfp-graphite-light">
        {t('label')}
      </label>
      <select
        id="sort-select"
        name="tri"
        value={current}
        onChange={onChange}
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
        className={`h-10 shrink-0 rounded-pssfp-button border border-pssfp-prune/30 bg-white px-4 font-ui text-sm font-medium text-pssfp-prune transition-colors hover:border-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-or focus-visible:ring-offset-2 ${
          enhanced ? 'sr-only focus:not-sr-only' : ''
        }`}
      >
        {t('submit')}
      </button>
    </form>
  );
}
