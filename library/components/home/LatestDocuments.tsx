import { getTranslations } from 'next-intl/server';
import { DocumentCard } from '@/components/document/DocumentCard';
import { StateMessage } from '@/components/catalogue/StateMessage';
import type { DocumentSummary } from '@/lib/api/types';
import { SectionHeading } from './SectionHeading';

/** Largeur rendue d'une couverture : 2 colonnes, 4 à partir de lg (conteneur 80 rem, marges du cadre). */
const COVER_SIZES = '(min-width: 1280px) 200px, (min-width: 1024px) 16vw, 32vw';

/** Derniers documents publiés (cible du lien « Nouveautés » de l'en-tête). */
export async function LatestDocuments({ docs }: { docs: DocumentSummary[] }): Promise<JSX.Element> {
  const t = await getTranslations('home.latest');
  return (
    <section id="nouveautes" data-testid="home-latest" aria-labelledby="latest-title" className="scroll-mt-24 border-y border-pssfp-lavande/30 bg-white py-16 sm:py-20 lg:py-24">
      <div className="mx-auto max-w-7xl px-4 sm:px-6">
        <SectionHeading id="latest-title" eyebrow={t('eyebrow')} title={t('title')} intro={t('intro')} />
        <div className="mt-10">
          {docs.length === 0 ? (
            <StateMessage kind="empty" title={t('empty')} />
          ) : (
            <ul className="grid grid-cols-2 gap-x-4 gap-y-10 sm:gap-x-6 lg:grid-cols-4">
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
