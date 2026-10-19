import Link from 'next/link';
import { ArrowRight, ArrowUpRight } from 'lucide-react';
import { getTranslations } from 'next-intl/server';
import type { MotCle } from '@/lib/api/types';
import { themeHref, themesHref } from '@/lib/routes';
import { featuredThemes } from '@/lib/themes';
import { SectionHeading } from './SectionHeading';

/** « Quel sujet vous intéresse ? » : thèmes réels du thésaurus, vers leur page ; masqué sans thème. */
export async function ThemesTeaser({ motsCles }: { motsCles: MotCle[] }): Promise<JSX.Element | null> {
  const themes = featuredThemes(motsCles);
  if (themes.length === 0) return null;
  const t = await getTranslations('home.themes');
  const tThemes = await getTranslations('themes');

  return (
    <section id="thematiques" data-testid="home-themes" aria-labelledby="themes-title" className="scroll-mt-24 py-16 sm:py-20 lg:py-24">
      <div className="mx-auto max-w-7xl px-4 sm:px-6">
        <SectionHeading
          id="themes-title"
          eyebrow={t('eyebrow')}
          title={t('title')}
          intro={t('intro')}
          action={
            <Link href={themesHref} className="group inline-flex min-h-6 items-center gap-1.5 rounded-sm font-ui text-sm font-semibold text-pssfp-prune transition-colors hover:text-pssfp-prune-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2">
              {t('all')}
              <ArrowRight size={16} aria-hidden="true" className="transition-transform duration-300 motion-safe:group-hover:translate-x-0.5" />
            </Link>
          }
        />
        <ul className="mt-10 grid grid-cols-1 gap-3 min-[420px]:grid-cols-2 lg:grid-cols-4">
          {themes.map((theme) => (
            <li key={theme.slug}>
              <Link
                href={themeHref(theme.slug)}
                className="group flex min-h-14 items-center justify-between gap-3 rounded-xl border border-pssfp-lavande/45 bg-white px-4 py-3 transition duration-300 ease-pssfp-out-expo hover:border-pssfp-prune/50 hover:bg-pssfp-mist focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2 motion-reduce:transition-none"
              >
                <span className="flex min-w-0 flex-col">
                  <span className="font-ui text-[0.9375rem] font-semibold text-pssfp-prune-dark">{theme.label}</span>
                  {theme.documents_count !== undefined && (
                    <span className="font-ui text-xs text-pssfp-graphite-light">{tThemes('count', { count: theme.documents_count })}</span>
                  )}
                </span>
                <ArrowUpRight size={16} aria-hidden="true" className="shrink-0 text-pssfp-prune-light transition-transform duration-300 motion-safe:group-hover:-translate-y-0.5 motion-safe:group-hover:translate-x-0.5" />
              </Link>
            </li>
          ))}
        </ul>
      </div>
    </section>
  );
}
