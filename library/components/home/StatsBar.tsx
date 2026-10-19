import { BookOpen, GraduationCap, Layers, Tags, type LucideIcon } from 'lucide-react';
import { getFormatter, getTranslations } from 'next-intl/server';
import { DOCUMENT_TYPES, type LibraryStats } from '@/lib/api/types';

interface StatsBarProps {
  stats: LibraryStats;
  /** Nombre de thèmes du thésaurus (null : inconnu ou indisponible, indicateur masqué). */
  themes: number | null;
}

interface Stat {
  key: 'documents' | 'collections' | 'themes' | 'promotions';
  value: number;
  Icon: LucideIcon;
}

/**
 * Indicateurs de la bibliothèque, tous issus de l'API : documents publiés, collections non vides,
 * thèmes du thésaurus, promotions (si l'API en compte). Un indicateur nul ou inconnu n'est pas affiché.
 */
export async function StatsBar({ stats, themes }: StatsBarProps): Promise<JSX.Element | null> {
  const t = await getTranslations('home.stats');
  const format = await getFormatter();
  const collections = DOCUMENT_TYPES.filter((type) => (stats.by_type[type] ?? 0) > 0).length;
  const items: Stat[] = [
    { key: 'documents' as const, value: stats.total, Icon: BookOpen },
    { key: 'collections' as const, value: collections, Icon: Layers },
    { key: 'themes' as const, value: themes ?? 0, Icon: Tags },
    { key: 'promotions' as const, value: stats.promotions_count ?? 0, Icon: GraduationCap },
  ].filter((item) => item.value > 0);
  if (items.length === 0) return null;

  return (
    <section aria-label={t('label')} data-testid="home-stats" className="border-b border-pssfp-lavande/30 bg-white">
      <ul className="mx-auto grid max-w-7xl grid-cols-2 px-4 sm:px-6 md:flex md:divide-x md:divide-pssfp-lavande/30">
        {items.map(({ key, value, Icon }) => (
          <li key={key} data-testid={`home-stat-${key}`} className="flex flex-1 items-center gap-3 py-5 md:justify-center md:px-6 md:py-6">
            <span aria-hidden="true" className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-pssfp-mist text-pssfp-prune-light">
              <Icon size={18} strokeWidth={1.75} />
            </span>
            <span className="flex flex-col">
              <span data-testid="home-stat-value" className="font-heading text-3xl font-semibold leading-none [font-variant-numeric:lining-nums_tabular-nums] text-pssfp-prune-dark">{format.number(value)}</span>
              <span className="mt-1 font-ui text-xs text-pssfp-graphite-light">{t(key, { count: value })}</span>
            </span>
          </li>
        ))}
      </ul>
    </section>
  );
}
