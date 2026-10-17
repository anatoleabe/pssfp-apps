import Link from 'next/link';
import { getTranslations } from 'next-intl/server';
import { AccessBadge } from '@/components/document/AccessBadge';
import { DocumentCover } from '@/components/document/DocumentCover';
import { DOCUMENT_TYPES, type DocumentSummary, type LibraryStats } from '@/lib/api/types';
import { collectionHref, documentHref, searchHref } from '@/lib/routes';
import { Eyebrow } from './Eyebrow';
import { SearchForm } from './SearchForm';

const FOCUS = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2 focus-visible:ring-offset-pssfp-ivoire';

interface HomeHeroProps {
  stats: LibraryStats;
  /**
   * Trois dernières nouveautés (colonne de droite, à partir de lg).
   * Pas de `priority` : la colonne est masquée sous lg, et le LCP mobile est le h1.
   */
  latest: DocumentSummary[];
}

export async function HomeHero({ stats, latest }: HomeHeroProps): Promise<JSX.Element> {
  const t = await getTranslations('home.hero');
  const tTypes = await getTranslations('types');
  const suggestions = t.raw('suggestions') as string[];
  const types = DOCUMENT_TYPES.filter((type) => (stats.by_type[type] ?? 0) > 0);

  return (
    <section data-testid="home-hero" aria-labelledby="home-title" className="relative isolate overflow-hidden border-b border-pssfp-lavande/30">
      {/* Atmosphère : halo lavande et filets verticaux, purement décoratifs. */}
      <div aria-hidden="true" className="pointer-events-none absolute inset-0 -z-10">
        <div className="absolute -right-40 -top-40 h-[34rem] w-[34rem] rounded-full bg-[radial-gradient(closest-side,rgba(165,146,189,0.28),transparent)]" />
        <div className="absolute -bottom-48 left-[-10rem] h-[26rem] w-[26rem] rounded-full bg-[radial-gradient(closest-side,rgba(212,175,106,0.16),transparent)]" />
      </div>

      <div className="mx-auto grid max-w-7xl gap-12 px-4 pb-14 pt-12 sm:px-6 sm:pb-20 sm:pt-16 lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)] lg:gap-16 lg:pb-24 lg:pt-20">
        <div className="min-w-0">
          {stats.total > 0 && <Eyebrow tone="or" testId="home-eyebrow">{t('eyebrow', { count: stats.total })}</Eyebrow>}
          <h1
            id="home-title"
            className="mt-5 max-w-[15ch] text-balance font-heading text-pssfp-h1 font-semibold text-pssfp-prune lg:text-[4.25rem] lg:leading-[1.02]"
          >
            {t('title')}
          </h1>

          <div className="mt-8 max-w-2xl sm:mt-10">
            <SearchForm size="hero" id="home-search-q" />
          </div>

          <div className="mt-5 flex max-w-2xl flex-wrap items-baseline gap-x-3 gap-y-1.5 text-sm">
            <span className="font-ui text-pssfp-graphite-light">{t('suggestionsLabel')}</span>
            <ul className="flex flex-wrap items-baseline gap-y-1.5 [&>li+li]:before:mx-2 [&>li+li]:before:text-pssfp-lavande [&>li+li]:before:content-['·']">
              {suggestions.map((q) => (
                <li key={q}>
                  <Link
                    href={searchHref(q)}
                    className={`rounded-sm font-medium text-pssfp-prune underline decoration-pssfp-lavande decoration-1 underline-offset-4 transition-colors hover:decoration-pssfp-prune ${FOCUS}`}
                  >
                    {q}
                  </Link>
                </li>
              ))}
            </ul>
          </div>

          {types.length > 0 && (
            <nav aria-label={t('typesLabel')} className="mt-8">
              <ul className="flex flex-wrap gap-2">
                {types.map((type) => (
                  <li key={type}>
                    <Link
                      href={collectionHref(type)}
                      className={`inline-flex h-10 items-center rounded-full border border-pssfp-lavande/70 bg-white/70 px-4 font-ui text-sm text-pssfp-graphite transition-colors duration-200 hover:border-pssfp-prune hover:bg-pssfp-prune hover:text-white active:bg-pssfp-prune-dark ${FOCUS}`}
                    >
                      {tTypes(`${type}.other`)}
                    </Link>
                  </li>
                ))}
              </ul>
            </nav>
          )}
        </div>

        {latest.length > 0 && (
          <aside aria-labelledby="home-latest-aside" className="hidden min-w-0 border-l border-pssfp-lavande/40 pl-10 lg:block">
            <h2 id="home-latest-aside" className="font-heading text-3xl font-semibold text-pssfp-prune">
              {t('latestTitle')}
            </h2>
            <ol className="mt-6 space-y-3">
              {latest.map((doc) => (
                <li key={doc.uuid}>
                  <Link
                    href={documentHref(doc.slug)}
                    className={`group -mx-3 flex items-start gap-4 rounded-xl p-3 transition duration-300 ease-pssfp-out-expo hover:bg-white hover:shadow-pssfp-elevated ${FOCUS}`}
                  >
                    <DocumentCover doc={doc} size="sm" sizes="80px" decorative />
                    <span className="flex min-w-0 flex-col gap-1.5 pt-0.5">
                      <span className="font-heading text-xl font-semibold leading-snug text-pssfp-graphite [overflow-wrap:anywhere] group-hover:text-pssfp-prune">
                        {doc.title}
                      </span>
                      <span className="font-ui text-xs font-medium uppercase tracking-[0.12em] text-pssfp-bleu-petrole">
                        {tTypes(`${doc.type}.one`)}
                      </span>
                      <span>
                        <AccessBadge mode={doc.access_mode} price={doc.price_xaf} />
                      </span>
                    </span>
                  </Link>
                </li>
              ))}
            </ol>
          </aside>
        )}
      </div>
    </section>
  );
}
