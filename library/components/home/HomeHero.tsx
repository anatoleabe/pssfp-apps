import Link from 'next/link';
import { Eye } from 'lucide-react';
import { getTranslations } from 'next-intl/server';
import { DocumentCover } from '@/components/document/DocumentCover';
import type { DocumentSummary } from '@/lib/api/types';
import { documentHref, searchHref } from '@/lib/routes';
import { Eyebrow } from './Eyebrow';
import { SearchForm } from './SearchForm';

/** Fond sombre : anneau de focus or (7,5:1 sur la prune « deep »). */
const FOCUS_DARK = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-or focus-visible:ring-offset-2 focus-visible:ring-offset-pssfp-prune-deep';

/** Position, rotation et profondeur des trois couvertures (gauche, centre, droite). */
const STACK = [
  'left-0 top-[14%] w-[38%] -rotate-[9deg] z-10',
  'left-[30%] top-0 w-[42%] rotate-[2deg] z-20',
  'right-0 top-[12%] w-[36%] rotate-[10deg] z-0',
] as const;

interface HomeHeroProps {
  /** Documents réels mis en scène à droite (les plus consultés, trois au plus). */
  showcase: DocumentSummary[];
}

/**
 * Héros prune : titre, recherche GET vers /recherche, suggestions, et composition de trois couvertures
 * réelles (les plus consultées). Pas de légende décorative inventée : la légende dit ce qui est montré.
 */
export async function HomeHero({ showcase }: HomeHeroProps): Promise<JSX.Element> {
  const t = await getTranslations('home.hero');
  const suggestions = t.raw('suggestions') as string[];
  const books = showcase.slice(0, STACK.length);

  return (
    <section data-testid="home-hero" aria-labelledby="home-title" className="relative isolate overflow-hidden bg-pssfp-prune-deep text-white">
      {/* Atmosphère : dégradé prune, halos et arcs concentriques (écho du logo), purement décoratifs. */}
      <div aria-hidden="true" className="pointer-events-none absolute inset-0 -z-10">
        <div className="absolute inset-0 bg-[linear-gradient(116deg,#211429_0%,#2E1C40_48%,#4A2E67_100%)]" />
        <div className="absolute -right-24 -top-40 h-[36rem] w-[36rem] rounded-full bg-[radial-gradient(closest-side,rgba(165,146,189,0.30),transparent)]" />
        <div className="absolute -bottom-56 -left-40 h-[30rem] w-[30rem] rounded-full bg-[radial-gradient(closest-side,rgba(92,58,126,0.45),transparent)]" />
        <div className="absolute -right-28 -top-48 h-[34rem] w-[34rem] rounded-full border border-white/[0.07] shadow-[0_0_0_4.5rem_rgba(255,255,255,0.025),0_0_0_9rem_rgba(255,255,255,0.018)]" />
      </div>

      <div className="mx-auto grid max-w-7xl items-center gap-12 px-4 pb-14 pt-12 sm:px-6 sm:pb-16 sm:pt-16 lg:grid-cols-[minmax(0,1.25fr)_minmax(0,1fr)] lg:gap-10 lg:pb-20 lg:pt-20">
        <div className="min-w-0">
          <Eyebrow tone="light">{t('eyebrow')}</Eyebrow>
          <h1 id="home-title" className="mt-5 text-balance font-heading text-[2.75rem] font-semibold leading-[1.02] tracking-[-0.015em] sm:text-6xl lg:text-[4.5rem]">
            {t('titleLead')} <em className="block font-medium italic text-pssfp-or-light">{t('titleAccent')}</em>
          </h1>
          <p className="mt-6 max-w-xl text-pssfp-lead text-white/85">{t('lead')}</p>

          <div className="mt-8 max-w-2xl">
            <SearchForm size="hero" id="home-search-q" tone="dark" />
          </div>

          <div className="mt-5 flex max-w-2xl flex-wrap items-center gap-x-3 gap-y-2">
            <span className="font-ui text-sm text-white/80">{t('suggestionsLabel')}</span>
            <ul className="flex flex-wrap gap-2">
              {suggestions.map((q) => (
                <li key={q}>
                  <Link
                    href={searchHref(q)}
                    className={`inline-flex min-h-8 items-center rounded-full border border-white/30 bg-white/[0.06] px-3.5 font-ui text-sm text-white transition-colors hover:border-white/60 hover:bg-white/[0.14] ${FOCUS_DARK}`}
                  >
                    {q}
                  </Link>
                </li>
              ))}
            </ul>
          </div>
        </div>

        {books.length > 0 && (
          <div className="relative mx-auto w-full max-w-[22rem] sm:max-w-md lg:max-w-none">
            <ul aria-label={t('showcaseLabel')} className="relative aspect-[5/4]">
              {books.map((doc, i) => (
                <li key={doc.uuid} className={`absolute ${STACK[i]}`}>
                  <Link
                    href={documentHref(doc.slug)}
                    className={`group block rounded-md transition-transform duration-500 ease-pssfp-out-expo motion-safe:hover:-translate-y-2 motion-reduce:transition-none ${FOCUS_DARK}`}
                  >
                    <DocumentCover doc={doc} size="md" decorative priority={i === 1} sizes="(min-width: 1024px) 240px, 40vw" className="shadow-[0_24px_40px_-12px_rgba(10,4,16,0.7)]" />
                    <span className="sr-only">{doc.title}</span>
                  </Link>
                </li>
              ))}
            </ul>
            <p className="relative z-30 -mt-6 ml-auto flex w-max max-w-full items-center gap-2.5 rounded-xl bg-white px-4 py-3 text-pssfp-prune-dark shadow-[0_8px_30px_rgba(19,12,34,0.45)] sm:-mt-8 lg:mr-6">
              <Eye size={16} strokeWidth={1.75} aria-hidden="true" className="shrink-0 text-pssfp-prune-light" />
              <span className="flex flex-col">
                <span className="font-ui text-sm font-semibold">{t('showcaseTitle')}</span>
                <span className="font-ui text-xs text-pssfp-graphite-light">{t('showcaseBody')}</span>
              </span>
            </p>
          </div>
        )}
      </div>
    </section>
  );
}
