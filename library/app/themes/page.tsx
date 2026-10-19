import type { Metadata } from 'next';
import { getTranslations } from 'next-intl/server';
import { StateMessage } from '@/components/catalogue/StateMessage';
import { SearchForm } from '@/components/home/SearchForm';
import { PageHeader } from '@/components/layout/PageHeader';
import { ThemeIndex } from '@/components/themes/ThemeIndex';
import { getMotsCles } from '@/lib/api/library';
import { isBuildPhase, unwrapOrThrow } from '@/lib/api/unwrap';
import { themesHref } from '@/lib/routes';
import { buildThemeTree } from '@/lib/themes';

export const revalidate = 300;

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations('themes');
  return {
    title: t('metaTitle'),
    description: t('metaDescription'),
    alternates: { canonical: themesHref },
    openGraph: { title: t('title'), description: t('metaDescription'), url: themesHref, type: 'website', locale: 'fr_FR' },
  };
}

/**
 * Index des thèmes (ISR 5 min). Thésaurus indispensable : un échec de l'API lève une exception
 * (`unwrapOrThrow`, la dernière version valide est conservée, sinon `app/error.tsx`) ; pendant
 * `next build` sans API (CI), message d'indisponibilité remplacé à la première revalidation réussie.
 */
export default async function ThemesPage(): Promise<JSX.Element> {
  const result = await getMotsCles();
  const t = await getTranslations('themes');
  const tCrumbs = await getTranslations('breadcrumbs');
  const tStates = await getTranslations('states');
  const unavailable = isBuildPhase() && !result.ok;
  const tree = unavailable ? [] : buildThemeTree(unwrapOrThrow(result).data);

  return (
    <>
      <PageHeader
        titleId="themes-title"
        testId="themes-header"
        title={t('title')}
        eyebrow={t('eyebrow')}
        breadcrumbs={[{ href: '/', label: tCrumbs('home') }, { label: tCrumbs('themes') }]}
        meta={<p className="max-w-xl text-pssfp-lead text-white/85">{t('intro')}</p>}
        aside={<SearchForm size="compact" id="themes-search-q" tone="dark" />}
      />
      <div className="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:py-16">
        {unavailable ? (
          <StateMessage kind="error" title={tStates('errorTitle')} body={tStates('errorBody')} />
        ) : tree.length === 0 ? (
          <StateMessage kind="empty" testId="themes-empty" title={t('emptyTitle')} body={t('emptyBody')} />
        ) : (
          <ThemeIndex tree={tree} />
        )}
      </div>
    </>
  );
}
