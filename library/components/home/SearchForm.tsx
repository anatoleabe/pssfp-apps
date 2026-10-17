import { Search } from 'lucide-react';
import { useTranslations } from 'next-intl';
import type { CatalogueFilters, DocumentType } from '@/lib/api/types';
import { filtersToSearchParams } from '@/lib/routes';

interface SearchFormProps {
  defaultQuery?: string;
  size?: 'hero' | 'compact';
  /** Identifiant du champ ; à changer si deux formulaires cohabitent sur une page. */
  id?: string;
  /** Limite la recherche à un type (champ caché `type`, lu par /recherche). */
  type?: DocumentType;
  /** Texte indicatif ; celui du catalogue par défaut. */
  placeholder?: string;
  /** Filtres reportés sur la nouvelle requête (champs cachés, sans tri ni page) ; prime sur `type`. */
  filters?: CatalogueFilters;
}

const STYLES = {
  hero: {
    shell: 'rounded-[0.875rem] p-1.5 shadow-pssfp-elevated',
    input: 'h-12 pl-11 text-base sm:h-14 sm:pl-12 sm:text-lg',
    icon: 'left-4 sm:left-[1.1rem]',
    iconSize: 20,
    button: 'h-12 px-4 text-sm sm:h-14 sm:px-7 sm:text-base',
  },
  compact: {
    shell: 'rounded-pssfp-button p-1 shadow-pssfp-soft',
    input: 'h-10 pl-9 text-sm',
    icon: 'left-3',
    iconSize: 16,
    button: 'h-10 px-4 text-sm',
  },
} as const;

/**
 * Formulaire GET vers /recherche (aucun JS client) : champ `q`, deux caractères au moins.
 * Réutilisé par l'accueil, la recherche et les pages de collection.
 */
export function SearchForm({ defaultQuery, size = 'compact', id = `search-q-${size}`, type, placeholder, filters }: SearchFormProps): JSX.Element {
  const t = useTranslations('search');
  const s = STYLES[size];
  const hidden = [...filtersToSearchParams(filters ? { ...filters, tri: undefined } : { type })];
  return (
    <form
      role="search"
      aria-label={t('formLabel')}
      action="/recherche"
      method="get"
      data-testid={`search-form-${size}`}
      className={`flex w-full items-stretch gap-1.5 border border-pssfp-lavande/50 bg-white transition-shadow focus-within:border-pssfp-prune/60 focus-within:shadow-pssfp-glow-prune ${s.shell}`}
    >
      {hidden.map(([name, value]) => (
        <input key={name} type="hidden" name={name} value={value} />
      ))}
      <label htmlFor={id} className="sr-only">
        {t('label')}
      </label>
      <div className="relative min-w-0 flex-1">
        <Search
          size={s.iconSize}
          aria-hidden="true"
          className={`pointer-events-none absolute top-1/2 -translate-y-1/2 text-pssfp-prune/70 ${s.icon}`}
        />
        <input
          id={id}
          type="search"
          name="q"
          required
          minLength={2}
          maxLength={200}
          defaultValue={defaultQuery}
          placeholder={placeholder ?? t('placeholder')}
          enterKeyHint="search"
          autoComplete="off"
          className={`w-full min-w-0 rounded-lg bg-transparent pr-2 text-pssfp-graphite placeholder:text-pssfp-graphite-light focus:outline-none ${s.input}`}
        />
      </div>
      <button
        type="submit"
        className={`shrink-0 rounded-lg bg-pssfp-prune font-ui font-medium text-white transition-colors hover:bg-pssfp-prune-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2 active:bg-pssfp-prune-dark ${s.button}`}
      >
        {t('submit')}
      </button>
    </form>
  );
}
