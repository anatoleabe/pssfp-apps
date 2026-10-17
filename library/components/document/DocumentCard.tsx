import Link from 'next/link';
import { useTranslations } from 'next-intl';
import type { DocumentSummary } from '@/lib/api/types';
import { sanitizeHighlight } from '@/lib/highlight';
import { documentHref } from '@/lib/routes';
import { AccessBadge } from './AccessBadge';
import { DocumentCover } from './DocumentCover';

const MAX_AUTHORS = 2;

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

const CARD =
  'group relative rounded-pssfp-card border border-pssfp-lavande/30 bg-white shadow-pssfp-soft transition duration-300 ease-pssfp-out-expo hover:shadow-pssfp-elevated focus-within:shadow-pssfp-elevated motion-safe:hover:-translate-y-0.5 motion-reduce:transition-none';

/** Lien étiré sur toute la carte ; l'anneau de focus suit le contour de la carte. */
const TITLE_LINK =
  'after:absolute after:inset-0 after:rounded-pssfp-card after:content-[""] focus-visible:outline-none focus-visible:after:ring-2 focus-visible:after:ring-pssfp-or focus-visible:after:ring-offset-2 [&_mark]:rounded-sm [&_mark]:bg-pssfp-or/35 [&_mark]:px-0.5 [&_mark]:text-inherit';

export function DocumentCard({ doc, highlight, layout = 'grid', headingLevel = 'h3', priority = false, coverSizes }: DocumentCardProps): JSX.Element {
  const t = useTranslations();
  const Heading = headingLevel;
  const shown = doc.authors.slice(0, MAX_AUTHORS).join(', ');
  const authors = doc.authors.length > MAX_AUTHORS ? t('document.authorsEtAl', { list: shown }) : shown;
  const isRow = layout === 'row';

  return (
    <article
      data-testid={`document-card-${doc.slug}`}
      className={`${CARD} ${isRow ? 'flex items-start gap-4 p-3 sm:gap-5 sm:p-4' : 'flex flex-col p-3'}`}
    >
      <DocumentCover doc={doc} size={isRow ? 'sm' : 'md'} priority={priority} sizes={coverSizes} />
      <div className={`flex min-w-0 flex-1 flex-col gap-2 ${isRow ? '' : 'mt-4 px-1 pb-1'}`}>
        <p className="flex flex-wrap font-ui text-xs font-medium uppercase tracking-[0.12em] text-pssfp-bleu-petrole [&>span+span]:before:mx-1.5 [&>span+span]:before:content-['·']">
          <span>{t(`types.${doc.type}.one`)}</span>
          {doc.year !== null && <span>{doc.year}</span>}
          {doc.promotion !== null && <span>{t('document.promotion', { n: doc.promotion })}</span>}
        </p>
        <Heading className={`font-heading font-semibold leading-snug hyphens-auto [overflow-wrap:anywhere] text-pssfp-prune ${isRow ? 'text-xl' : 'text-lg sm:text-[1.35rem]'}`}>
          {highlight ? (
            <Link href={documentHref(doc.slug)} className={TITLE_LINK} dangerouslySetInnerHTML={{ __html: sanitizeHighlight(highlight) }} />
          ) : (
            <Link href={documentHref(doc.slug)} className={TITLE_LINK}>
              {doc.title}
            </Link>
          )}
        </Heading>
        {doc.subtitle && <p className="line-clamp-2 text-sm text-pssfp-graphite">{doc.subtitle}</p>}
        {authors && <p className="text-sm text-pssfp-graphite-light">{authors}</p>}
        <div className={isRow ? 'pt-1' : 'mt-auto pt-2'}>
          <AccessBadge mode={doc.access_mode} price={doc.price_xaf} />
        </div>
      </div>
    </article>
  );
}
