import type { Metadata } from 'next';
import Link from 'next/link';
import { cache } from 'react';
import { ArrowUpRight, Eye, Tag } from 'lucide-react';
import { getFormatter, getTranslations } from 'next-intl/server';
import { AccessAction } from '@/components/document/AccessAction';
import { AccessBadge } from '@/components/document/AccessBadge';
import { CitationBox } from '@/components/document/CitationBox';
import { DocumentCard } from '@/components/document/DocumentCard';
import { DocumentCover } from '@/components/document/DocumentCover';
import { ViewBeacon } from '@/components/document/ViewBeacon';
import { Eyebrow } from '@/components/home/Eyebrow';
import { Breadcrumbs } from '@/components/layout/Breadcrumbs';
import { MAIN_SITE_URL } from '@/components/layout/nav-config';
import { JsonLd } from '@/components/seo/JsonLd';
import { getDocument } from '@/lib/api/library';
import type { DocumentDetail, DocumentSummary } from '@/lib/api/types';
import { unwrapOrThrow } from '@/lib/api/unwrap';
import { META_DESCRIPTION_MAX, abstractParagraphs, doiUrl, documentJsonLd, excerpt, isBookType, shortTitle } from '@/lib/document';
import { collectionHref, documentHref } from '@/lib/routes';
import { siteUrl } from '@/lib/site';

export const revalidate = 300;

/**
 * Aucune fiche prérendue au build (la CI n'a pas d'API) : chaque fiche est rendue à la première
 * visite puis mise en cache 5 min (ISR). Sous Next 14, sans cette fonction la route serait
 * entièrement dynamique (`Cache-Control: no-store`, HTML jamais mis en cache).
 */
export function generateStaticParams(): { slug: string }[] {
  return [];
}

const RELATED_MAX = 4;
/** Largeur rendue d'une couverture liée : 2 colonnes, 4 à partir de lg (conteneur 80 rem, marges du cadre). */
const RELATED_COVER_SIZES = '(min-width: 1280px) 200px, (min-width: 1024px) 16vw, 32vw';

interface DocumentPageProps {
  params: { slug: string };
}

/**
 * 404 → `notFound()` ; autre échec → exception (l'ISR garde la dernière version valide, sinon `app/error.tsx`).
 * `cache()` : `generateMetadata` et la page partagent un seul appel (et un seul 404 ou échec) par rendu.
 */
const loadDocument = cache(async (slug: string): Promise<{ data: DocumentDetail; related: DocumentSummary[] }> => unwrapOrThrow(await getDocument(slug)));

async function metaDescription(doc: DocumentDetail): Promise<string> {
  if (doc.abstract && doc.abstract.trim()) return excerpt(doc.abstract, META_DESCRIPTION_MAX);
  const t = await getTranslations('fiche');
  const tTypes = await getTranslations('types');
  return excerpt(t('metaDescriptionFallback', { type: tTypes(`${doc.type}.one`), title: doc.title }), META_DESCRIPTION_MAX);
}

export async function generateMetadata({ params }: DocumentPageProps): Promise<Metadata> {
  const { data: doc } = await loadDocument(params.slug);
  const description = await metaDescription(doc);
  const path = documentHref(doc.slug);
  const images = doc.cover_url ? [doc.cover_url] : undefined;
  const common = { title: doc.title, description, url: path, locale: 'fr_FR', images };
  return {
    title: doc.title,
    description,
    alternates: { canonical: path },
    openGraph: isBookType(doc.type)
      ? { ...common, type: 'book', authors: doc.authors, ...(doc.isbn ? { isbn: doc.isbn } : {}), tags: doc.mots_cles.map((m) => m.label) }
      : { ...common, type: 'article', authors: doc.authors, tags: doc.mots_cles.map((m) => m.label) },
  };
}

interface MetaItem {
  key: string;
  label: string;
  value: JSX.Element | string;
}

/** Lignes de la notice : un champ absent (ou une spécialité sans intitulé connu) n'est pas affiché. */
async function metadataItems(doc: DocumentDetail): Promise<MetaItem[]> {
  const t = await getTranslations('fiche');
  const tSpecialites = await getTranslations('specialites');
  const tLanguages = await getTranslations('filters.languages');
  const tAction = await getTranslations('access.action');
  const format = await getFormatter();
  const doi = doiUrl(doc.doi);
  const items: (MetaItem | null)[] = [
    doc.year !== null ? { key: 'year', label: t('fields.year'), value: String(doc.year) } : null,
    doc.promotion !== null ? { key: 'promotion', label: t('fields.promotion'), value: t('promotionValue', { n: doc.promotion }) } : null,
    doc.specialite && tSpecialites.has(doc.specialite) ? { key: 'specialite', label: t('fields.specialite'), value: tSpecialites(doc.specialite) } : null,
    tLanguages.has(doc.language) ? { key: 'language', label: t('fields.language'), value: tLanguages(doc.language) } : null,
    doc.pages_count ? { key: 'pages', label: t('fields.pages'), value: format.number(doc.pages_count) } : null,
    doc.journal ? { key: 'journal', label: t('fields.journal'), value: doc.journal } : null,
    doc.doi
      ? {
          key: 'doi',
          label: t('fields.doi'),
          value: doi ? (
            <a href={doi} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 break-all rounded-sm text-pssfp-prune underline decoration-pssfp-or/70 underline-offset-4 hover:decoration-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2">
              {doc.doi}
              <ArrowUpRight size={14} aria-hidden="true" className="shrink-0" />
              <span className="sr-only"> {tAction('newWindow')}</span>
            </a>
          ) : (
            doc.doi
          ),
        }
      : null,
    doc.isbn ? { key: 'isbn', label: t('fields.isbn'), value: doc.isbn } : null,
  ];
  return items.filter((item): item is MetaItem => item !== null);
}

/**
 * Fiche document (maquette C) : couverture collante à gauche (≥ lg), notice, action d'accès,
 * résumé, mots-clés, citation ; documents liés en pied de page. Le document est indispensable :
 * `unwrapOrThrow` (404 → page introuvable, autre échec → frontière d'erreur).
 */
export default async function DocumentPage({ params }: DocumentPageProps): Promise<JSX.Element> {
  const { data: doc, related } = await loadDocument(params.slug);
  const t = await getTranslations('fiche');
  const tDoc = await getTranslations('document');
  const tTypes = await getTranslations('types');
  const tCrumbs = await getTranslations('breadcrumbs');

  const items = await metadataItems(doc);
  const paragraphs = abstractParagraphs(doc.abstract);
  const relatedDocs = related.slice(0, RELATED_MAX);
  const description = await metaDescription(doc);
  const jsonLd = documentJsonLd(doc, { origin: siteUrl().origin, publisherName: t('publisherName'), publisherUrl: `${MAIN_SITE_URL}/`, description });

  return (
    <>
      <JsonLd data={jsonLd} />
      <ViewBeacon uuid={doc.uuid} />

      {/* Pas d'overflow-hidden ici : il deviendrait le conteneur de la couverture collante et la figerait.
          Le décor est rogné par son propre calque absolu. */}
      <div className="relative isolate">
        <div aria-hidden="true" className="pointer-events-none absolute inset-x-0 top-0 -z-10 h-[22rem] overflow-hidden bg-[linear-gradient(180deg,#F1EBF6_0%,rgba(250,247,242,0)_100%)]">
          <div className="absolute -right-32 -top-48 h-[28rem] w-[28rem] rounded-full border border-pssfp-lavande/30 shadow-[0_0_0_4rem_rgba(165,146,189,0.06)]" />
        </div>
        <div className="mx-auto max-w-7xl px-4 pb-14 pt-8 sm:px-6 lg:pb-20 lg:pt-10">
          <Breadcrumbs items={[{ href: '/', label: tCrumbs('home') }, { href: collectionHref(doc.type), label: tTypes(`${doc.type}.other`) }, { label: shortTitle(doc.title) }]} />

          <div className="mt-8 grid gap-8 sm:gap-10 lg:mt-10 lg:grid-cols-[19rem_minmax(0,1fr)] lg:gap-16 xl:grid-cols-[21rem_minmax(0,1fr)]">
            <div data-testid="document-cover-column" className="lg:sticky lg:top-28 lg:self-start">
              <div className="w-60 rounded-[1.25rem] bg-pssfp-mist p-6 ring-1 ring-pssfp-lavande/25 sm:w-64 lg:w-full lg:p-8 [&>div]:max-w-none">
                <DocumentCover doc={doc} size="lg" priority sizes="(min-width: 1280px) 272px, (min-width: 1024px) 240px, 208px" />
              </div>
            </div>

            <article aria-labelledby="document-title" className="min-w-0 max-w-3xl">
              <header>
                <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                  <Eyebrow>{tTypes(`${doc.type}.one`)}</Eyebrow>
                  <AccessBadge mode={doc.access_mode} price={doc.price_xaf} />
                </div>
                <h1 id="document-title" className="mt-4 text-balance font-heading text-pssfp-h1 font-semibold text-pssfp-prune-dark hyphens-auto [overflow-wrap:anywhere]">
                  {doc.title}
                </h1>
                {doc.subtitle && <p className="mt-3 text-pssfp-lead text-pssfp-graphite">{doc.subtitle}</p>}
                {doc.authors.length > 0 && (
                  <p data-testid="document-authors" className="mt-4 font-ui text-base font-medium text-pssfp-graphite">
                    <span className="sr-only">{t('authorsLabel')} </span>
                    {doc.authors.join(', ')}
                  </p>
                )}
              </header>

              <section aria-labelledby="access-title" className="mt-8 rounded-pssfp-card border border-pssfp-lavande/35 bg-white p-5 shadow-pssfp-soft sm:p-6">
                <h2 id="access-title" className="font-ui text-xs font-semibold uppercase tracking-[0.16em] text-pssfp-prune-light">
                  {t('accessTitle')}
                </h2>
                <div className="mt-4 flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
                  <AccessAction doc={doc} />
                  <p className="inline-flex items-center gap-1.5 font-ui text-sm text-pssfp-graphite-light">
                    <Eye size={16} aria-hidden="true" />
                    {tDoc('views', { count: doc.views_count })}
                  </p>
                </div>
              </section>

              {items.length > 0 && (
                <section aria-labelledby="details-title" className="mt-8">
                  <h2 id="details-title" className="font-heading text-2xl font-semibold text-pssfp-prune-dark">
                    {t('detailsTitle')}
                  </h2>
                  <dl data-testid="document-details" className="mt-4 flex flex-wrap overflow-hidden rounded-pssfp-card border border-pssfp-lavande/35 bg-white">
                    {items.map((item) => (
                      <div key={item.key} className="min-w-0 flex-1 basis-44 px-4 py-3.5 shadow-[-1px_-1px_0_0_rgba(165,146,189,0.35)]">
                        <dt className="font-ui text-xs font-medium uppercase tracking-[0.14em] text-pssfp-bleu-petrole">{item.label}</dt>
                        <dd className="mt-1 break-words text-pssfp-graphite">{item.value}</dd>
                      </div>
                    ))}
                  </dl>
                </section>
              )}

              {paragraphs.length > 0 && (
                <section data-testid="document-abstract" aria-labelledby="abstract-title" className="mt-12">
                  <h2 id="abstract-title" className="font-heading text-pssfp-h2 font-semibold text-pssfp-prune-dark">
                    {tDoc('abstract')}
                  </h2>
                  <div className="mt-4 space-y-4 text-lg leading-relaxed text-pssfp-graphite">
                    {paragraphs.map((paragraph, index) => (
                      <p key={index} className="whitespace-pre-line">
                        {paragraph}
                      </p>
                    ))}
                  </div>
                </section>
              )}

              {doc.mots_cles.length > 0 && (
                <section data-testid="document-keywords" aria-labelledby="keywords-title" className="mt-10">
                  <h2 id="keywords-title" className="font-heading text-2xl font-semibold text-pssfp-prune-dark">
                    {tDoc('keywords')}
                  </h2>
                  <p className="mt-1 text-sm text-pssfp-graphite-light">{t('keywordsHint')}</p>
                  <ul className="mt-4 flex flex-wrap gap-2">
                    {doc.mots_cles.map((mc) => (
                      <li key={mc.slug}>
                        <Link
                          href={collectionHref(doc.type, { mot_cle: mc.slug })}
                          className="inline-flex min-h-9 items-center gap-1.5 rounded-full border border-pssfp-prune/60 bg-white px-3.5 py-1.5 font-ui text-sm text-pssfp-prune transition-colors hover:border-pssfp-prune hover:bg-pssfp-prune hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2 motion-reduce:transition-none"
                        >
                          <Tag size={13} strokeWidth={1.75} aria-hidden="true" />
                          {mc.label}
                        </Link>
                      </li>
                    ))}
                  </ul>
                </section>
              )}

              <div className="mt-12">
                <CitationBox doc={doc} />
              </div>
            </article>
          </div>
        </div>
      </div>

      {relatedDocs.length > 0 && (
        <section data-testid="document-related" aria-labelledby="related-title" className="border-t border-pssfp-lavande/30 bg-white py-14 sm:py-16">
          <div className="mx-auto max-w-7xl px-4 sm:px-6">
            <Eyebrow>{t('relatedEyebrow')}</Eyebrow>
            <h2 id="related-title" className="mt-4 font-heading text-pssfp-h2 font-semibold text-pssfp-prune-dark">
              {tDoc('related')}
            </h2>
            <ul className="mt-10 grid grid-cols-2 gap-x-4 gap-y-10 sm:gap-x-6 lg:grid-cols-4">
              {relatedDocs.map((rel) => (
                <li key={rel.uuid} className="flex [&>article]:w-full">
                  <DocumentCard doc={rel} coverSizes={RELATED_COVER_SIZES} />
                </li>
              ))}
            </ul>
          </div>
        </section>
      )}
    </>
  );
}
