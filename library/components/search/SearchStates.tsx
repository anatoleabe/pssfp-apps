import Link from 'next/link';
import { Search } from 'lucide-react';
import { useTranslations } from 'next-intl';
import type { DocumentType } from '@/lib/api/types';
import { collectionHref, searchHref } from '@/lib/routes';

/** Collections proposées quand la recherche ne donne rien (ou pas encore). */
const SUGGESTED_COLLECTIONS: readonly DocumentType[] = ['memoire', 'these', 'texte_loi', 'ouvrage_numerique', 'article_scientifique', 'cours'];

const FOCUS = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-or focus-visible:ring-offset-2';
const CHIP = `inline-flex h-9 items-center rounded-full border border-pssfp-lavande/60 bg-white px-3.5 font-ui text-sm text-pssfp-prune transition-colors hover:border-pssfp-prune ${FOCUS}`;
const TEXT_LINK = `rounded-sm font-medium text-pssfp-prune underline decoration-pssfp-lavande decoration-1 underline-offset-4 transition-colors hover:decoration-pssfp-prune ${FOCUS}`;

/** Liens vers quelques collections (états vide et invite). */
export function CollectionLinks(): JSX.Element {
  const t = useTranslations('types');
  return (
    <ul className="flex flex-wrap gap-2">
      {SUGGESTED_COLLECTIONS.map((type) => (
        <li key={type}>
          <Link href={collectionHref(type)} className={CHIP}>
            {t(`${type}.other`)}
          </Link>
        </li>
      ))}
    </ul>
  );
}

/** Invite affichée sans requête valable (moins de deux caractères) : aucune requête n'est émise. */
export function SearchInvite(): JSX.Element {
  const t = useTranslations('search');
  const tHero = useTranslations('home.hero');
  const suggestions = tHero.raw('suggestions') as string[];
  return (
    <div data-testid="search-invite" className="rounded-pssfp-card border border-dashed border-pssfp-lavande bg-white p-6 sm:p-8">
      <div className="flex flex-col items-start gap-4 sm:flex-row">
        <span className="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-pssfp-ivoire text-pssfp-prune">
          <Search size={20} strokeWidth={1.75} aria-hidden="true" />
        </span>
        <div className="min-w-0 space-y-2">
          <p className="font-heading text-2xl font-semibold leading-tight text-pssfp-prune">{t('inviteTitle')}</p>
          <p className="max-w-2xl text-pssfp-graphite">{t('inviteBody')}</p>
        </div>
      </div>
      <div className="mt-6 flex flex-wrap items-baseline gap-x-2 gap-y-1.5 text-sm sm:pl-[3.75rem]">
        <span className="font-ui text-pssfp-graphite-light">{tHero('suggestionsLabel')}</span>
        <ul className="flex flex-wrap items-baseline gap-y-1.5 [&>li+li]:before:mx-2 [&>li+li]:before:text-pssfp-lavande [&>li+li]:before:content-['·']">
          {suggestions.map((q) => (
            <li key={q}>
              <Link href={searchHref(q)} className={TEXT_LINK}>
                {q}
              </Link>
            </li>
          ))}
        </ul>
      </div>
      <div className="mt-6 sm:pl-[3.75rem]">
        <p className="mb-3 font-ui text-sm text-pssfp-graphite-light">{t('inviteCollections')}</p>
        <CollectionLinks />
      </div>
    </div>
  );
}

/** Squelette des cartes pendant la requête (décoratif : l'état passe par aria-busy et la région vivante). */
export function SearchSkeleton(): JSX.Element {
  return (
    <ul data-testid="search-skeleton" aria-hidden="true" className="grid gap-3 sm:gap-4 md:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
      {[0, 1, 2, 3].map((i) => (
        <li key={i} className="flex gap-4 rounded-pssfp-card border border-pssfp-lavande/30 bg-white p-3 shadow-pssfp-soft sm:gap-5 sm:p-4">
          <span className="h-28 w-20 shrink-0 rounded-md bg-pssfp-lavande/25 motion-safe:animate-pulse" />
          <span className="flex min-w-0 flex-1 flex-col gap-3 pt-1">
            <span className="h-3 w-24 rounded bg-pssfp-lavande/30 motion-safe:animate-pulse" />
            <span className="h-5 w-11/12 rounded bg-pssfp-lavande/30 motion-safe:animate-pulse" />
            <span className="h-5 w-2/3 rounded bg-pssfp-lavande/30 motion-safe:animate-pulse" />
            <span className="h-3 w-32 rounded bg-pssfp-lavande/20 motion-safe:animate-pulse" />
          </span>
        </li>
      ))}
    </ul>
  );
}
