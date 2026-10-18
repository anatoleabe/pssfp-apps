import type { Metadata } from 'next';
import { Suspense } from 'react';
import { getTranslations } from 'next-intl/server';
import { Eyebrow } from '@/components/home/Eyebrow';
import { SearchForm } from '@/components/home/SearchForm';
import { Breadcrumbs } from '@/components/layout/Breadcrumbs';
import { SearchExperience } from '@/components/search/SearchExperience';
import { SearchScope } from '@/components/search/SearchScope';
import { SearchSkeleton } from '@/components/search/SearchStates';
import { getMotsCles } from '@/lib/api/library';
import type { CatalogueFilters } from '@/lib/api/types';
import { filtersToSearchParams, searchParamsToFilters } from '@/lib/routes';
import { MAX_QUERY_LENGTH } from '@/lib/search';

type SearchParams = Record<string, string | string[] | undefined>;

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations('search');
  return {
    title: t('metaTitle'),
    description: t('metaDescription'),
    // Pages de résultats : jamais indexées (contenu dérivé de la requête), liens suivis ; pas de canonique.
    robots: { index: false, follow: true },
  };
}

/**
 * Coquille serveur de la recherche : en-tête, formulaire prérempli et portée (puces des filtres reportés).
 * Les résultats sont demandés par le navigateur (`SearchExperience`), jamais ici : pas d'ISR ni de
 * frontière d'erreur. Seul appel serveur, facultatif : les intitulés des mots-clés (`getMotsCles`, mis en
 * cache), uniquement si `mot_cle` est filtré ; en cas d'échec, ce filtre n'est simplement pas reporté.
 */
export default async function SearchPage({ searchParams }: { searchParams: SearchParams }): Promise<JSX.Element> {
  const t = await getTranslations('search');
  const tCrumbs = await getTranslations('breadcrumbs');
  const raw = Array.isArray(searchParams.q) ? searchParams.q[0] : searchParams.q;
  const q = (raw ?? '').trim().slice(0, MAX_QUERY_LENGTH);
  const { tri: _sort, ...filters } = searchParamsToFilters(searchParams);
  const motsClesResult = filters.mot_cle ? await getMotsCles() : null;
  const motsCles = motsClesResult?.ok ? motsClesResult.data.data : [];
  const tSpecialites = await getTranslations('specialites');
  // Filtres reportés sur une nouvelle requête (page 1) : seulement ceux qu'une puce rend visibles et retirables.
  // Spécialité ou mot-clé sans intitulé connu : ni puce ni report (aucun filtre invisible).
  const { specialite, mot_cle: motCle, ...rest } = filters;
  const carried: CatalogueFilters = {
    ...rest,
    ...(specialite && tSpecialites.has(specialite) ? { specialite } : {}),
    ...(motCle && motsCles.some((m) => m.slug === motCle) ? { mot_cle: motCle } : {}),
  };

  return (
    <>
      <section aria-labelledby="search-title" className="relative isolate overflow-hidden border-b border-pssfp-lavande/30">
        <div aria-hidden="true" className="pointer-events-none absolute -right-40 -top-48 -z-10 h-[30rem] w-[30rem] rounded-full bg-[radial-gradient(closest-side,rgba(165,146,189,0.26),transparent)]" />
        <div className="mx-auto max-w-7xl px-4 pb-10 pt-8 sm:px-6 sm:pb-12 lg:pt-10">
          <Breadcrumbs items={[{ href: '/', label: tCrumbs('home') }, { label: t('title') }]} />
          <div className="mt-8 grid gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,40rem)] lg:items-end lg:gap-16">
            <div className="min-w-0">
              <Eyebrow tone="or">{t('eyebrow')}</Eyebrow>
              <h1 id="search-title" className="mt-4 text-balance font-heading text-pssfp-h1 font-semibold text-pssfp-prune lg:text-[4rem] lg:leading-none">
                {t('title')}
              </h1>
            </div>
            <div className="min-w-0">
              {/* `key` : remonté à chaque changement d'URL, le champ et les champs cachés reflètent toujours l'URL. */}
              <SearchForm key={`${q}|${filtersToSearchParams(carried).toString()}`} size="hero" id="search-page-q" defaultQuery={q} filters={carried} />
              <SearchScope q={q} filters={carried} motsCles={motsCles} />
            </div>
          </div>
        </div>
      </section>

      <Suspense fallback={<SearchFallback />}>
        <SearchExperience />
      </Suspense>
    </>
  );
}

function SearchFallback(): JSX.Element {
  return (
    <div className="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:py-14">
      <SearchSkeleton />
    </div>
  );
}
