import Link from 'next/link';
import { useTranslations } from 'next-intl';
import { ACCESS_MODES, LANGUAGES, type CatalogueFilters, type DocumentType, type MotCle } from '@/lib/api/types';

interface FilterPanelProps {
  type: DocumentType;
  filters: CatalogueFilters;
  motsCles: MotCle[];
  /** Préfixe des identifiants : le panneau est rendu deux fois (tiroir mobile, colonne ≥ lg). */
  idPrefix: string;
}

const LEGEND = 'font-ui text-xs font-semibold uppercase tracking-[0.16em] text-pssfp-prune';
const FIELD =
  'h-10 w-full min-w-0 rounded-pssfp-button border border-pssfp-lavande/60 bg-white px-3 text-sm text-pssfp-graphite transition-colors hover:border-pssfp-prune/50 focus-visible:border-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune/30';
const SMALL_LABEL = 'mb-1 block font-ui text-xs text-pssfp-graphite-light';

/** Ordre d'affichage : chaque mot-clé parent suivi de ses enfants. */
function orderMotsCles(motsCles: MotCle[]): Array<MotCle & { child: boolean }> {
  const byLabel = (a: MotCle, b: MotCle): number => a.label.localeCompare(b.label, 'fr');
  const slugs = new Set(motsCles.map((m) => m.slug));
  const roots = motsCles.filter((m) => m.parent_slug === null || !slugs.has(m.parent_slug)).sort(byLabel);
  return roots.flatMap((root) => [
    { ...root, child: false },
    ...motsCles.filter((m) => m.parent_slug === root.slug).sort(byLabel).map((m) => ({ ...m, child: true })),
  ]);
}

/**
 * Formulaire GET des filtres d'une collection : fonctionne sans JavaScript.
 * Les paramètres sans champ visible (tri, spécialité, promotion hors mémoires) sont conservés en champs cachés.
 */
export function FilterPanel({ type, filters, motsCles, idPrefix }: FilterPanelProps): JSX.Element {
  const t = useTranslations('filters');
  const tAccess = useTranslations('access');
  const tCatalogue = useTranslations('catalogue');
  const id = (name: string): string => `${idPrefix}-${name}`;
  const action = `/collections/${type}`;
  const showPromotion = type === 'memoire';
  const hidden: Array<[string, string | number | undefined]> = [
    ['tri', filters.tri],
    ['specialite', filters.specialite],
    ...(showPromotion ? [] : ([['promotion', filters.promotion]] as Array<[string, number | undefined]>)),
  ];

  return (
    <form action={action} method="get" aria-label={tCatalogue('filtersTitle')} data-testid={`filter-form-${idPrefix}`} className="flex flex-col gap-7">
      {hidden.map(([name, value]) => (value === undefined ? null : <input key={name} type="hidden" name={name} value={value} />))}

      <fieldset>
        <legend className={LEGEND}>{t('acces')}</legend>
        <div className="mt-3 flex flex-wrap gap-2">
          {(['', ...ACCESS_MODES] as const).map((mode) => {
            const inputId = id(`acces-${mode || 'tous'}`);
            return (
              <label
                key={inputId}
                htmlFor={inputId}
                className="relative inline-flex h-9 cursor-pointer items-center rounded-full border border-pssfp-lavande/60 bg-white px-3.5 font-ui text-sm text-pssfp-graphite transition-colors hover:border-pssfp-prune/60 has-[:checked]:border-pssfp-prune has-[:checked]:bg-pssfp-prune has-[:checked]:text-white has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-pssfp-or has-[:focus-visible]:ring-offset-2"
              >
                {/* Bouton radio natif invisible posé sur toute la pastille : clavier, clic et lecteurs d'écran natifs. */}
                <input
                  id={inputId}
                  type="radio"
                  name="acces"
                  value={mode}
                  defaultChecked={(filters.acces ?? '') === mode}
                  className="absolute inset-0 h-full w-full cursor-pointer appearance-none rounded-full opacity-0"
                />
                {mode === '' ? t('all') : tAccess(mode)}
              </label>
            );
          })}
        </div>
      </fieldset>

      <fieldset>
        <legend className={LEGEND}>{t('annee')}</legend>
        <div className="mt-3 grid grid-cols-2 gap-3">
          <div>
            <label htmlFor={id('annee-min')} className={SMALL_LABEL}>
              {t('anneeMin')}
            </label>
            <input id={id('annee-min')} type="number" name="annee_min" inputMode="numeric" min={1900} max={2100} step={1} defaultValue={filters.annee_min} className={FIELD} />
          </div>
          <div>
            <label htmlFor={id('annee-max')} className={SMALL_LABEL}>
              {t('anneeMax')}
            </label>
            <input id={id('annee-max')} type="number" name="annee_max" inputMode="numeric" min={1900} max={2100} step={1} defaultValue={filters.annee_max} className={FIELD} />
          </div>
        </div>
      </fieldset>

      {showPromotion && (
        <div>
          <label htmlFor={id('promotion')} className={LEGEND}>
            {t('promotion')}
          </label>
          <input id={id('promotion')} type="number" name="promotion" inputMode="numeric" min={1} max={99} step={1} defaultValue={filters.promotion} className={`${FIELD} mt-3`} />
        </div>
      )}

      <div>
        <label htmlFor={id('langue')} className={LEGEND}>
          {t('langue')}
        </label>
        <select id={id('langue')} name="langue" defaultValue={filters.langue ?? ''} className={`${FIELD} mt-3`}>
          <option value="">{t('allLanguages')}</option>
          {LANGUAGES.map((code) => (
            <option key={code} value={code}>
              {t(`languages.${code}`)}
            </option>
          ))}
        </select>
      </div>

      {motsCles.length > 0 && (
        <div>
          <label htmlFor={id('mot-cle')} className={LEGEND}>
            {t('motCle')}
          </label>
          <select id={id('mot-cle')} name="mot_cle" defaultValue={filters.mot_cle ?? ''} className={`${FIELD} mt-3`}>
            <option value="">{t('all')}</option>
            {orderMotsCles(motsCles).map((m) => (
              <option key={m.slug} value={m.slug}>
                {m.child ? t('motCleChild', { label: m.label }) : m.label}
              </option>
            ))}
          </select>
        </div>
      )}

      <div className="flex flex-wrap items-center gap-3 border-t border-pssfp-lavande/30 pt-5">
        <button
          type="submit"
          className="inline-flex h-10 items-center justify-center rounded-pssfp-button bg-pssfp-prune px-5 font-ui text-sm font-medium text-white shadow-pssfp-soft transition-colors hover:bg-pssfp-prune-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-or focus-visible:ring-offset-2 active:bg-pssfp-prune-dark"
        >
          {t('apply')}
        </button>
        <Link
          href={action}
          className="rounded-sm font-ui text-sm font-medium text-pssfp-prune underline decoration-pssfp-lavande underline-offset-4 transition-colors hover:decoration-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2"
        >
          {t('reset')}
        </Link>
      </div>
    </form>
  );
}
