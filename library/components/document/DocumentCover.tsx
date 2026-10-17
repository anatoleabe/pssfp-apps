import Image from 'next/image';
import { useTranslations } from 'next-intl';
import type { DocumentSummary, DocumentType } from '@/lib/api/types';

export type CoverSize = 'sm' | 'md' | 'lg';

interface CoverTheme {
  /** Dégradé plat : il va toujours vers la teinte qui renforce le contraste du texte. */
  surface: string;
  text: string;
}

/*
 * Contrastes du texte (WCAG, teinte de départ = cas le plus défavorable) :
 * blanc / prune #4A2E67 11,18:1 · blanc / pétrole #0F3A4A 12,18:1 ·
 * blanc / graphite #3C3C3C 11,03:1 · prune foncé #3A2452 / or #D4AF6A 6,52:1 ·
 * prune foncé / lavande #A592BD 4,79:1 · blanc / prune clair #5C3A7E 8,89:1.
 */
const PRUNE: CoverTheme = { surface: 'from-pssfp-prune to-pssfp-prune-dark', text: 'text-white' };
const PRUNE_LIGHT: CoverTheme = { surface: 'from-pssfp-prune-light to-pssfp-prune', text: 'text-white' };
const LAVANDE: CoverTheme = { surface: 'from-pssfp-lavande to-[#B8A8CC]', text: 'text-pssfp-prune-dark' };

export const COVER_THEMES: Record<DocumentType, CoverTheme> = {
  memoire: PRUNE,
  rapport_stage: PRUNE,
  these: { surface: 'from-pssfp-bleu-petrole to-pssfp-bleu-petrole-dark', text: 'text-white' },
  texte_loi: { surface: 'from-pssfp-graphite to-[#2A2A2A]', text: 'text-white' },
  ouvrage_numerique: { surface: 'from-pssfp-or to-pssfp-or-light', text: 'text-pssfp-prune-dark' },
  cours: LAVANDE,
  ressource_pedagogique: LAVANDE,
  article_scientifique: PRUNE_LIGHT,
  acte_conference: PRUNE_LIGHT,
  bulletin: PRUNE_LIGHT,
};

const SIZES: Record<CoverSize, { box: string; sizes: string; pad: string; title: string; type: string }> = {
  sm: { box: 'w-16 sm:w-20', sizes: '80px', pad: 'p-2', title: 'text-2xl', type: 'hidden' },
  md: { box: 'w-full', sizes: '(min-width: 1024px) 240px, (min-width: 640px) 33vw, 90vw', pad: 'p-4', title: 'text-base line-clamp-4 sm:text-lg sm:line-clamp-5', type: 'text-[0.65rem]' },
  lg: { box: 'w-full max-w-xs', sizes: '(min-width: 1024px) 320px, 80vw', pad: 'p-6', title: 'text-2xl line-clamp-6', type: 'text-xs' },
};

interface DocumentCoverProps {
  doc: DocumentSummary;
  size?: CoverSize;
  priority?: boolean;
  /** Remplace l'attribut `sizes` par défaut de la taille quand la mise en page le connaît mieux. */
  sizes?: string;
}

/** Couverture 3:4 : image si l'API en fournit une, sinon couverture typographique décorative. */
export function DocumentCover({ doc, size = 'md', priority = false, sizes }: DocumentCoverProps): JSX.Element {
  const t = useTranslations();
  const s = SIZES[size];
  const frame = `relative aspect-[3/4] shrink-0 overflow-hidden rounded-md shadow-pssfp-soft ${s.box}`;

  if (doc.cover_url) {
    return (
      <div className={`${frame} bg-pssfp-lavande/20`}>
        <Image
          src={doc.cover_url}
          alt={t('document.coverAlt', { title: doc.title })}
          fill
          sizes={sizes ?? s.sizes}
          priority={priority}
          className="object-cover"
        />
      </div>
    );
  }

  const theme = COVER_THEMES[doc.type] ?? PRUNE_LIGHT;
  return (
    <div
      aria-hidden="true"
      data-testid="document-cover-typo"
      className={`${frame} flex flex-col justify-between bg-gradient-to-br ${theme.surface} ${theme.text} ${s.pad} before:absolute before:inset-y-0 before:left-0 before:w-1.5 before:bg-black/15`}
    >
      <span className={`font-ui font-medium uppercase tracking-[0.16em] ${s.type}`}>{t(`types.${doc.type}.one`)}</span>
      {size === 'sm' ? (
        <span className={`font-heading font-semibold leading-none ${s.title}`}>{doc.title.charAt(0)}</span>
      ) : (
        <span className="flex flex-col gap-3">
          <span className="block h-0.5 w-8 bg-current opacity-60" />
          <span className={`font-heading font-semibold leading-tight ${s.title}`}>{doc.title}</span>
        </span>
      )}
    </div>
  );
}
