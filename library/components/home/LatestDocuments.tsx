import { getTranslations } from 'next-intl/server';
import { DocumentCard } from '@/components/document/DocumentCard';
import { StateMessage } from '@/components/catalogue/StateMessage';
import type { DocumentSummary } from '@/lib/api/types';
import { Eyebrow } from './Eyebrow';

/** Largeur rendue d'une carte : 2 colonnes, 3 à partir de md, 6 à partir de xl (conteneur 80 rem). */
const COVER_SIZES = '(min-width: 1280px) 190px, (min-width: 768px) 30vw, 45vw';

/** Six derniers documents publiés ; pas de lien « voir tout » en V1 (aucune page dédiée). */
export async function LatestDocuments({ docs }: { docs: DocumentSummary[] | null }): Promise<JSX.Element> {
  const t = await getTranslations('home.latest');
  return (
    <section data-testid="home-latest" aria-labelledby="latest-title" className="border-y border-pssfp-lavande/30 bg-white py-16 sm:py-20">
      <div className="mx-auto max-w-7xl px-4 sm:px-6">
        <Eyebrow>{t('eyebrow')}</Eyebrow>
        <h2 id="latest-title" className="mt-4 font-heading text-pssfp-h2 font-semibold text-pssfp-prune">
          {t('title')}
        </h2>
        <div className="mt-10">
          {docs === null ? (
            <StateMessage kind="error" title={t('error')} />
          ) : docs.length === 0 ? (
            <StateMessage kind="empty" title={t('empty')} />
          ) : (
            <ul className="grid grid-cols-2 gap-3 sm:gap-5 md:grid-cols-3 xl:grid-cols-6">
              {docs.map((doc) => (
                <li key={doc.uuid} className="flex [&>article]:w-full">
                  <DocumentCard doc={doc} coverSizes={COVER_SIZES} />
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
    </section>
  );
}
