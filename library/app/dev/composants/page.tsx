import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { useTranslations } from 'next-intl';
import { getTranslations } from 'next-intl/server';
import { Pagination } from '@/components/catalogue/Pagination';
import { StateMessage } from '@/components/catalogue/StateMessage';
import { AccessAction } from '@/components/document/AccessAction';
import { CitationBox } from '@/components/document/CitationBox';
import { DocumentCard } from '@/components/document/DocumentCard';
import { DocumentCover } from '@/components/document/DocumentCover';
import { ViewBeacon } from '@/components/document/ViewBeacon';
import {
  DEMO_ACTIONS,
  DEMO_COUVERTURE,
  DEMO_COVERS,
  DEMO_LIBRE,
  DEMO_PAYANT,
  DEMO_RESERVE,
  DEMO_ROW,
  DEMO_SURLIGNAGE,
  DEMO_TRAP_TITLE,
} from './fixtures';

const isProduction = (): boolean => process.env.NODE_ENV === 'production';

export async function generateMetadata(): Promise<Metadata> {
  if (isProduction()) notFound();
  const t = await getTranslations('devComponents');
  return { title: t('metaTitle'), robots: { index: false, follow: false } };
}

const SECTION_TITLE = 'font-heading text-pssfp-h2 font-semibold text-pssfp-prune';

/** Page de démonstration des composants, absente en production. */
export default function ComposantsDemoPage(): JSX.Element {
  if (isProduction()) notFound();
  return <Demo />;
}

function Demo(): JSX.Element {
  const t = useTranslations('devComponents');
  const tStates = useTranslations('states');

  return (
    <div className="mx-auto max-w-7xl space-y-16 px-4 py-12 sm:px-6 md:py-16">
      <ViewBeacon uuid={DEMO_LIBRE.uuid} />
      <header className="max-w-3xl">
        <p className="font-ui text-xs font-medium uppercase tracking-[0.18em] text-pssfp-bleu-petrole">{t('eyebrow')}</p>
        <h1 className="mt-3 font-heading text-pssfp-h1 font-bold text-pssfp-prune">{t('title')}</h1>
        <p className="mt-4 text-pssfp-lead">{t('intro')}</p>
      </header>

      <section aria-labelledby="demo-grid" className="space-y-6">
        <h2 id="demo-grid" className={SECTION_TITLE}>{t('cardsGrid')}</h2>
        <div className="grid grid-cols-1 gap-6 min-[420px]:grid-cols-2 lg:grid-cols-4">
          {[DEMO_LIBRE, DEMO_RESERVE, DEMO_PAYANT, DEMO_COUVERTURE].map((doc) => (
            <DocumentCard key={doc.slug} doc={doc} />
          ))}
        </div>
      </section>

      <section aria-labelledby="demo-row" className="space-y-6">
        <h2 id="demo-row" className={SECTION_TITLE}>{t('cardsRow')}</h2>
        <div className="grid max-w-3xl gap-4">
          <DocumentCard doc={DEMO_ROW} layout="row" />
          <DocumentCard doc={DEMO_SURLIGNAGE} layout="row" highlight={DEMO_TRAP_TITLE} />
        </div>
      </section>

      <section aria-labelledby="demo-covers" className="space-y-6">
        <h2 id="demo-covers" className={SECTION_TITLE}>{t('covers')}</h2>
        <ul className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
          {DEMO_COVERS.map((doc) => (
            <li key={doc.slug}>
              <DocumentCover doc={doc} size="md" />
            </li>
          ))}
        </ul>
      </section>

      <section aria-labelledby="demo-actions" className="space-y-6">
        <h2 id="demo-actions" className={SECTION_TITLE}>{t('actions')}</h2>
        <div className="grid gap-6 md:grid-cols-2">
          {DEMO_ACTIONS.map((doc) => (
            <div key={doc.slug} className="space-y-3">
              <h3 className="font-ui text-xs font-medium uppercase tracking-[0.14em] text-pssfp-bleu-petrole">
                {t(`reason.${doc.access.reason as 'public' | 'external' | 'reserved' | 'paid'}`)}
              </h3>
              <AccessAction doc={doc} />
            </div>
          ))}
        </div>
      </section>

      <section aria-labelledby="demo-citation" className="max-w-3xl space-y-6">
        <h2 id="demo-citation" className={SECTION_TITLE}>{t('citation')}</h2>
        <CitationBox doc={DEMO_LIBRE} />
      </section>

      <section aria-labelledby="demo-pagination" className="space-y-6">
        <h2 id="demo-pagination" className={SECTION_TITLE}>{t('pagination')}</h2>
        <Pagination page={5} lastPage={12} hrefFor={(p) => `/dev/composants?page=${p}`} />
      </section>

      <section aria-labelledby="demo-states" className="max-w-3xl space-y-6">
        <h2 id="demo-states" className={SECTION_TITLE}>{t('states')}</h2>
        <StateMessage kind="empty" title={tStates('emptyTitle')} body={tStates('emptyBody')} />
        <StateMessage kind="error" title={tStates('errorTitle')} body={tStates('errorBody')} />
      </section>
    </div>
  );
}
