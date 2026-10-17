import Link from 'next/link';
import { getTranslations } from 'next-intl/server';
import { DocumentCover } from '@/components/document/DocumentCover';
import { StateMessage } from '@/components/catalogue/StateMessage';
import type { DocumentSummary } from '@/lib/api/types';
import { documentHref } from '@/lib/routes';
import { Eyebrow } from './Eyebrow';
import { MostReadControls } from './MostReadControls';

const LIST_ID = 'most-read-list';
/** Couvertures de 9 rem (mobile) puis 10 rem. */
const COVER_SIZES = '160px';

/** Bandeau des 10 documents les plus consultés : liste ordonnée défilant dans son propre conteneur. */
export async function MostRead({ docs }: { docs: DocumentSummary[] }): Promise<JSX.Element> {
  const t = await getTranslations('home.mostRead');
  const hasItems = docs.length > 0;

  return (
    <section data-testid="home-most-read" aria-labelledby="most-read-title" className="py-16 sm:py-20">
      <div className="mx-auto max-w-7xl px-4 sm:px-6">
        <div className="flex items-end justify-between gap-6">
          <div>
            <Eyebrow tone="or">{t('eyebrow')}</Eyebrow>
            <h2 id="most-read-title" className="mt-4 font-heading text-pssfp-h2 font-semibold text-pssfp-prune">
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
              className="scrollbar-none -mx-4 flex snap-x snap-mandatory scroll-px-4 gap-4 overflow-x-auto px-4 pb-6 pt-2 sm:mx-0 sm:scroll-px-0 sm:gap-6 sm:px-0"
            >
              {docs.map((doc, i) => (
                <li key={doc.uuid} className="w-36 shrink-0 snap-start sm:w-40">
                  <Link
                    href={documentHref(doc.slug)}
                    className="group block rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-or focus-visible:ring-offset-4 focus-visible:ring-offset-pssfp-ivoire"
                  >
                    <span className="block rounded-md transition duration-300 ease-pssfp-out-expo group-hover:shadow-pssfp-floating motion-safe:group-hover:-translate-y-1 motion-reduce:transition-none">
                      <DocumentCover doc={doc} size="md" sizes={COVER_SIZES} />
                    </span>
                    <span className="mt-3 flex gap-2.5">
                      <span aria-hidden="true" className="font-heading text-2xl font-semibold leading-none tabular-nums text-pssfp-or-dark">
                        {String(i + 1).padStart(2, '0')}
                      </span>
                      <span className="line-clamp-3 min-w-0 font-heading text-base font-semibold leading-snug text-pssfp-graphite [overflow-wrap:anywhere] group-hover:text-pssfp-prune">
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
