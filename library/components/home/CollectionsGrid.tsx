import Link from 'next/link';
import { ArrowRight, ArrowUpRight } from 'lucide-react';
import { getTranslations } from 'next-intl/server';
import { StateMessage } from '@/components/catalogue/StateMessage';
import { DOCUMENT_TYPES, type DocumentType, type LibraryStats } from '@/lib/api/types';
import { collectionHref, themesHref } from '@/lib/routes';
import { CollectionCard } from './CollectionCard';
import { SectionHeading } from './SectionHeading';

/**
 * Collections non vides (`stats.by_type`), de la plus fournie à la moins fournie : une collection
 * sans document n'a ni carte ni lien. Catalogue vide : message d'attente. Ouvrages payants : bandeau
 * dédié seulement si l'API en compte.
 */
export async function CollectionsGrid({ stats }: { stats: LibraryStats }): Promise<JSX.Element> {
  const t = await getTranslations('home.collections');
  const count = (type: DocumentType): number => stats.by_type[type] ?? 0;
  const types = DOCUMENT_TYPES.filter((type) => count(type) > 0).sort((a, b) => count(b) - count(a));

  return (
    <section id="collections" aria-labelledby="collections-title" className="scroll-mt-24 py-16 sm:py-20 lg:py-24">
      <div className="mx-auto max-w-7xl px-4 sm:px-6">
        <SectionHeading
          id="collections-title"
          eyebrow={t('eyebrow')}
          title={t('title')}
          intro={t('intro')}
          action={
            <Link href={themesHref} className="group inline-flex min-h-6 items-center gap-1.5 rounded-sm font-ui text-sm font-semibold text-pssfp-prune transition-colors hover:text-pssfp-prune-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2">
              {t('themesLink')}
              <ArrowRight size={16} aria-hidden="true" className="transition-transform duration-300 motion-safe:group-hover:translate-x-0.5" />
            </Link>
          }
        />

        {stats.total === 0 ? (
          <div className="mt-10">
            <StateMessage kind="empty" live={false} testId="collections-empty" title={t('emptyTitle')} body={t('emptyBody')} />
          </div>
        ) : (
          <ul className="mt-10 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 lg:gap-5">
            {types.map((type) => (
              <li key={type}>
                <CollectionCard type={type} count={count(type)} />
              </li>
            ))}
            {stats.paid_count > 0 && (
              <li className="sm:col-span-2 lg:col-span-3">
                <Link
                  href={collectionHref('ouvrage_numerique', { acces: 'payant' })}
                  data-testid="collection-tile-premium"
                  className="group flex flex-col gap-3 rounded-pssfp-card border border-pssfp-or/60 bg-[#F6ECD9] px-6 py-5 text-pssfp-prune-dark transition duration-300 ease-pssfp-out-expo hover:shadow-pssfp-elevated focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2 sm:flex-row sm:items-center sm:justify-between"
                >
                  <span className="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:gap-5">
                    <span className="font-heading text-2xl font-semibold">{t('premiumTitle')}</span>
                    <span className="font-ui text-xs font-semibold uppercase tracking-[0.16em] text-pssfp-or-dark">{t('premiumBody')}</span>
                  </span>
                  <span className="flex items-center gap-3 font-ui text-sm">
                    {t('premiumCount', { count: stats.paid_count })}
                    <ArrowUpRight size={18} aria-hidden="true" />
                  </span>
                </Link>
              </li>
            )}
          </ul>
        )}
      </div>
    </section>
  );
}
