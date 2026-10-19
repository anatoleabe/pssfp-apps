import Link from 'next/link';
import { Tag } from 'lucide-react';
import { useTranslations } from 'next-intl';
import type { DocumentSummary } from '@/lib/api/types';
import { sanitizeHighlight } from '@/lib/highlight';
import { documentHref } from '@/lib/routes';
import { AccessBadge } from './AccessBadge';
import { DocumentCover } from './DocumentCover';

const MAX_AUTHORS = 2;
/** Mots-clés affichés au pied de la carte (les premiers rattachés au document). */
const MAX_TOPICS = 2;

interface DocumentCardProps {
  doc: DocumentSummary;
  /** Titre surligné renvoyé par la recherche (échappé + <mark>) ; re-nettoyé ici. */
  highlight?: string;
  layout?: 'grid' | 'row';
  headingLevel?: 'h2' | 'h3';
  priority?: boolean;
  /** Attribut `sizes` de la couverture, selon la grille qui accueille la carte. */
  coverSizes?: string;
}

/** Lien étiré sur toute la carte ; l'anneau de focus suit le contour de la carte. */
const TITLE_LINK =
  'after:absolute after:inset-0 after:rounded-pssfp-card after:content-[""] focus-visible:outline-none focus-visible:after:ring-2 focus-visible:after:ring-pssfp-prune focus-visible:after:ring-offset-2 [&_mark]:rounded-sm [&_mark]:bg-pssfp-or/35 [&_mark]:px-0.5 [&_mark]:text-inherit';

/** Sur-titre : type · année · promotion (prune claire 8,9:1 sur blanc, 8,3:1 sur ivoire). */
function Kicker({ doc }: { doc: DocumentSummary }): JSX.Element {
  const t = useTranslations();
  return (
    <p className="flex flex-wrap font-ui text-[0.7rem] font-semibold uppercase tracking-[0.14em] text-pssfp-prune-light [&>span+span]:before:mx-1.5 [&>span+span]:before:content-['·']">
      <span>{t(`types.${doc.type}.one`)}</span>
      {doc.year !== null && <span className="[font-variant-numeric:lining-nums_tabular-nums]">{doc.year}</span>}
      {doc.promotion !== null && <span>{t('document.promotion', { n: doc.promotion })}</span>}
    </p>
  );
}

/** Premiers mots-clés du document (données réelles) : rien si aucun. */
function Topics({ doc }: { doc: DocumentSummary }): JSX.Element | null {
  const topics = doc.mots_cles.slice(0, MAX_TOPICS).map((m) => m.label);
  if (topics.length === 0) return null;
  return (
    <p className="flex items-start gap-1.5 font-ui text-xs text-pssfp-graphite-light">
      <Tag size={13} strokeWidth={1.75} aria-hidden="true" className="mt-0.5 shrink-0" />
      <span className="line-clamp-1">{topics.join(' · ')}</span>
    </p>
  );
}

/**
 * Carte de document. Grille : couverture posée sur un fond lavande très clair, badge d'accès réel
 * en surimpression, puis type, titre, auteurs et mots-clés. Ligne : version compacte des listes.
 * Le titre est le seul lien (étiré sur toute la carte).
 */
export function DocumentCard({ doc, highlight, layout = 'grid', headingLevel = 'h3', priority = false, coverSizes }: DocumentCardProps): JSX.Element {
  const t = useTranslations();
  const Heading = headingLevel;
  const shown = doc.authors.slice(0, MAX_AUTHORS).join(', ');
  const authors = doc.authors.length > MAX_AUTHORS ? t('document.authorsEtAl', { list: shown }) : shown;
  const isRow = layout === 'row';

  const title = highlight ? (
    <Link href={documentHref(doc.slug)} className={TITLE_LINK} dangerouslySetInnerHTML={{ __html: sanitizeHighlight(highlight) }} />
  ) : (
    <Link href={documentHref(doc.slug)} className={TITLE_LINK}>
      {doc.title}
    </Link>
  );

  if (isRow) {
    return (
      <article
        data-testid={`document-card-${doc.slug}`}
        className="group relative flex items-start gap-4 rounded-pssfp-card border border-pssfp-lavande/35 bg-white p-3 shadow-pssfp-soft transition duration-300 ease-pssfp-out-expo hover:border-pssfp-lavande hover:shadow-pssfp-elevated focus-within:shadow-pssfp-elevated motion-safe:hover:-translate-y-0.5 motion-reduce:transition-none sm:gap-5 sm:p-4"
      >
        <div className="shrink-0 rounded-xl bg-pssfp-mist p-2.5">
          <DocumentCover doc={doc} size="sm" priority={priority} sizes={coverSizes} />
        </div>
        <div className="flex min-w-0 flex-1 flex-col gap-1.5 pt-0.5">
          <Kicker doc={doc} />
          <Heading className="font-body text-lg font-semibold leading-snug text-pssfp-prune-dark hyphens-auto [overflow-wrap:anywhere] group-hover:text-pssfp-prune">{title}</Heading>
          {doc.subtitle && <p className="line-clamp-2 text-sm text-pssfp-graphite">{doc.subtitle}</p>}
          {authors && <p className="text-sm text-pssfp-graphite-light">{authors}</p>}
          <div className="flex flex-wrap items-center gap-x-4 gap-y-2 pt-1">
            <AccessBadge mode={doc.access_mode} price={doc.price_xaf} />
            <Topics doc={doc} />
          </div>
        </div>
      </article>
    );
  }

  return (
    <article data-testid={`document-card-${doc.slug}`} className="group relative flex flex-col rounded-pssfp-card">
      <div className="relative flex justify-center rounded-pssfp-card bg-pssfp-mist px-[16%] pb-6 pt-9 transition-colors duration-300 group-hover:bg-[#ECE5F3] motion-reduce:transition-none">
        <span className="absolute left-3 top-3 z-10">
          <AccessBadge mode={doc.access_mode} price={doc.price_xaf} floating />
        </span>
        <span className="block w-full transition-transform duration-300 ease-pssfp-out-expo motion-safe:group-hover:-translate-y-1 motion-reduce:transition-none">
          <DocumentCover doc={doc} size="md" priority={priority} sizes={coverSizes} />
        </span>
      </div>
      <div className="mt-4 flex flex-1 flex-col gap-1.5 px-1">
        <Kicker doc={doc} />
        <Heading className="line-clamp-3 font-body text-base font-semibold leading-snug text-pssfp-prune-dark hyphens-auto [overflow-wrap:anywhere] group-hover:text-pssfp-prune sm:text-[1.0625rem]">
          {title}
        </Heading>
        {authors && <p className="line-clamp-1 text-sm text-pssfp-graphite-light">{authors}</p>}
        <div className="mt-auto pt-1">
          <Topics doc={doc} />
        </div>
      </div>
    </article>
  );
}
