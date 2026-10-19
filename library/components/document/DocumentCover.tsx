import Image from 'next/image';
import { useTranslations } from 'next-intl';
import type { DocumentSummary, DocumentType } from '@/lib/api/types';

export type CoverSize = 'sm' | 'md' | 'lg';

interface CoverTheme {
  /** Dégradé du « livre » : il va vers la teinte qui renforce le contraste du texte. */
  surface: string;
  text: string;
}

/*
 * Couverture de repli (aucune image fournie) : un « livre » typographique aux couleurs de la charte,
 * sans logo ni mention institutionnelle — jamais l'imitation d'une couverture officielle.
 * Contrastes du texte (teinte de départ = cas le plus défavorable) :
 * blanc / prune #4A2E67 11,18:1 · blanc / prune clair #5C3A7E 8,89:1 · blanc / pétrole #0F3A4A 12,18:1 ·
 * blanc / graphite #3C3C3C 11,03:1 · blanc / pétrole clair #1D5A70 7,64:1 ·
 * prune foncé #3A2452 / or clair #E5C788 8,27:1 · prune foncé / lavande #A592BD 4,79:1.
 */
const PRUNE: CoverTheme = { surface: 'from-pssfp-prune to-pssfp-prune-deep', text: 'text-white' };
const PRUNE_LIGHT: CoverTheme = { surface: 'from-pssfp-prune-light to-pssfp-prune-dark', text: 'text-white' };
const LAVANDE: CoverTheme = { surface: 'from-pssfp-lavande to-[#C3B5D6]', text: 'text-pssfp-prune-dark' };

export const COVER_THEMES: Record<DocumentType, CoverTheme> = {
  memoire: PRUNE,
  rapport_stage: PRUNE,
  these: { surface: 'from-pssfp-bleu-petrole to-pssfp-bleu-petrole-dark', text: 'text-white' },
  texte_loi: { surface: 'from-pssfp-graphite to-[#26202C]', text: 'text-white' },
  ouvrage_numerique: { surface: 'from-pssfp-or-light to-pssfp-or', text: 'text-pssfp-prune-dark' },
  cours: LAVANDE,
  ressource_pedagogique: LAVANDE,
  article_scientifique: PRUNE_LIGHT,
  acte_conference: PRUNE_LIGHT,
  bulletin: PRUNE_LIGHT,
  rapport: { surface: 'from-[#1D5A70] to-pssfp-bleu-petrole', text: 'text-white' },
};

const SIZES: Record<CoverSize, { box: string; sizes: string; pad: string; title: string; type: string }> = {
  sm: { box: 'w-16 sm:w-20', sizes: '80px', pad: 'p-2', title: 'text-2xl', type: 'hidden' },
  md: { box: 'w-full', sizes: '(min-width: 1024px) 240px, (min-width: 640px) 33vw, 90vw', pad: 'px-4 py-5', title: 'text-base line-clamp-4 sm:text-lg sm:line-clamp-5', type: 'text-[0.6rem]' },
  lg: { box: 'w-full max-w-xs', sizes: '(min-width: 1024px) 320px, 80vw', pad: 'px-6 py-7', title: 'text-2xl line-clamp-6 hyphens-auto [overflow-wrap:anywhere]', type: 'text-[0.7rem]' },
};

/** Volume du livre : ombre portée, tranche claire à droite, dos ombré à gauche. */
const BOOK =
  'shadow-[0_10px_24px_-8px_rgba(33,20,41,0.45),0_2px_6px_rgba(33,20,41,0.18)] after:pointer-events-none after:absolute after:inset-y-0 after:left-0 after:w-2 after:bg-gradient-to-r after:from-black/25 after:to-transparent after:content-[""]';

interface DocumentCoverProps {
  doc: DocumentSummary;
  size?: CoverSize;
  priority?: boolean;
  /** Remplace l'attribut `sizes` par défaut de la taille quand la mise en page le connaît mieux. */
  sizes?: string;
  /** Vrai quand la couverture est dans un lien qui porte déjà le titre : `alt=""` (pas de doublon vocal). */
  decorative?: boolean;
  className?: string;
}

/** Couverture 3:4 : image si l'API en fournit une, sinon couverture typographique décorative. */
export function DocumentCover({ doc, size = 'md', priority = false, sizes, decorative = false, className = '' }: DocumentCoverProps): JSX.Element {
  const t = useTranslations();
  const s = SIZES[size];
  const frame = `relative aspect-[3/4] shrink-0 overflow-hidden rounded-[0.3rem] ${BOOK} ${s.box} ${className}`;

  if (doc.cover_url) {
    return (
      <div className={`${frame} bg-pssfp-mist`}>
        <Image
          src={doc.cover_url}
          alt={decorative ? '' : t('document.coverAlt', { title: doc.title })}
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
    <div aria-hidden="true" data-testid="document-cover-typo" className={`${frame} bg-gradient-to-br ${theme.surface} ${theme.text}`}>
      {size === 'sm' ? (
        <span className={`flex h-full items-end ${s.pad}`}>
          <span className={`font-heading font-semibold leading-none ${s.title}`}>{doc.title.charAt(0)}</span>
        </span>
      ) : (
        // Filet intérieur : cadre de page de titre, à la manière d'un ouvrage relié.
        <span className={`absolute inset-2 flex flex-col justify-between rounded-[0.2rem] border ${s.pad} [border-color:color-mix(in_srgb,currentColor_28%,transparent)]`}>
          <span className={`font-ui font-medium uppercase tracking-[0.16em] opacity-90 ${s.type}`}>{t(`types.${doc.type}.one`)}</span>
          <span className={`font-heading font-semibold leading-tight ${s.title}`}>{doc.title}</span>
          <span className="flex items-center gap-2">
            <span className="block h-px w-6 bg-current opacity-60" />
            {doc.year !== null && <span className={`font-ui [font-variant-numeric:lining-nums_tabular-nums] opacity-90 ${s.type}`}>{doc.year}</span>}
          </span>
        </span>
      )}
    </div>
  );
}
