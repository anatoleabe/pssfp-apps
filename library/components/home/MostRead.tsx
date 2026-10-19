import Link from 'next/link';
import { getTranslations } from 'next-intl/server';
import { DocumentCover } from '@/components/document/DocumentCover';
import { StateMessage } from '@/components/catalogue/StateMessage';
import type { DocumentSummary } from '@/lib/api/types';
import { documentHref } from '@/lib/routes';
import { Eyebrow } from './Eyebrow';
import { MostReadControls } from './MostReadControls';

const LIST_ID = 'most-read-list';
/** Couvertures dans un cadre de 10 rem (mobile) puis 11 rem. */
const COVER_SIZES = '144px';

/** Bandeau des 10 documents les plus consultés : liste ordonnée défilant dans son propre conteneur. */
export async function MostRead({ docs }: { docs: DocumentSummary[] }): Promise<JSX.Element> {
  const t = await getTranslations('home.mostRead');
  const hasItems = docs.length > 0;

  return (
    <section data-testid="home-most-read" aria-labelledby="most-read-title" className="border-t border-pssfp-lavande/30 bg-white py-16 sm:py-20">
      <div className="mx-auto max-w-7xl px-4 sm:px-6">
        <div className="flex items-end justify-between gap-6">
          <div>
            <Eyebrow>{t('eyebrow')}</Eyebrow>
            <h2 id="most-read-title" className="mt-4 font-heading text-pssfp-h2 font-semibold text-pssfp-prune-dark">
              {t('title')}
            </h2>
          </div>
          {hasItems && <MostReadControls listId={LIST_ID} />}
        </div>

        <div className="mt-10">
          {!hasItems ? (
            <StateMessage kind="empty" title={t('empty')} />
          ) : (
            <ol
              id={LIST_ID}
              data-testid={LIST_ID}
              aria-labelledby="most-read-title"
              // Marges intérieures ≥ 6 px (anneau 2 px + décalage 4 px) : l'anneau de focus n'est pas rogné par le défilement.
              className="scrollbar-none -mx-4 flex snap-x snap-mandatory scroll-px-4 gap-4 overflow-x-auto px-4 pb-6 pt-2 sm:-mx-2 sm:scroll-px-2 sm:gap-6 sm:px-2"
            >
              {docs.map((doc, i) => (
                <li key={doc.uuid} className="w-40 shrink-0 snap-start sm:w-44">
                  <Link
                    href={documentHref(doc.slug)}
                    className="group block rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-4 focus-visible:ring-offset-white"
                  >
                    <span className="block rounded-pssfp-card bg-pssfp-mist px-4 pb-4 pt-5">
                      <span className="block transition-transform duration-300 ease-pssfp-out-expo motion-safe:group-hover:-translate-y-1 motion-reduce:transition-none">
                        <DocumentCover doc={doc} size="md" sizes={COVER_SIZES} decorative />
                      </span>
                    </span>
                    <span className="mt-3 flex gap-2.5">
                      <span aria-hidden="true" className="font-heading text-2xl font-semibold leading-none [font-variant-numeric:lining-nums_tabular-nums] text-pssfp-or-dark">
                        {String(i + 1).padStart(2, '0')}
                      </span>
                      <span className="line-clamp-3 min-w-0 font-body text-[0.9375rem] font-semibold leading-snug text-pssfp-prune-dark [overflow-wrap:anywhere] group-hover:text-pssfp-prune">
                        {doc.title}
                      </span>
                    </span>
                  </Link>
                </li>
              ))}
            </ol>
          )}
        </div>
      </div>
    </section>
  );
}
