import Link from 'next/link';
import { ArrowUpRight, Award, BookOpen, ChartColumn, ClipboardList, FileText, GraduationCap, Library, Mic, Newspaper, Presentation, Scale, type LucideIcon } from 'lucide-react';
import { useFormatter, useTranslations } from 'next-intl';
import type { DocumentType } from '@/lib/api/types';
import { collectionHref } from '@/lib/routes';

/** Pictogramme et halo décoratif (coin supérieur droit) de chaque collection. */
const COLLECTION_STYLE: Record<DocumentType, { Icon: LucideIcon; halo: string }> = {
  memoire: { Icon: GraduationCap, halo: 'bg-pssfp-lavande/25' },
  these: { Icon: Award, halo: 'bg-pssfp-bleu-petrole/10' },
  ouvrage_numerique: { Icon: BookOpen, halo: 'bg-pssfp-lavande/25' },
  texte_loi: { Icon: Scale, halo: 'bg-pssfp-or/20' },
  article_scientifique: { Icon: FileText, halo: 'bg-pssfp-prune/10' },
  cours: { Icon: Presentation, halo: 'bg-pssfp-lavande/25' },
  acte_conference: { Icon: Mic, halo: 'bg-pssfp-or/20' },
  rapport_stage: { Icon: ClipboardList, halo: 'bg-pssfp-prune/10' },
  bulletin: { Icon: Newspaper, halo: 'bg-pssfp-bleu-petrole/10' },
  ressource_pedagogique: { Icon: Library, halo: 'bg-pssfp-lavande/25' },
  rapport: { Icon: ChartColumn, halo: 'bg-pssfp-prune/10' },
};

interface CollectionCardProps {
  type: DocumentType;
  /** Nombre de documents publiés (`stats.by_type`) : la carte n'est rendue que s'il est > 0. */
  count: number;
}

/**
 * Carte d'une collection non vide : pictogramme, nom, description, nombre réel de documents.
 * La carte entière est un lien vers la collection.
 */
export function CollectionCard({ type, count }: CollectionCardProps): JSX.Element {
  const t = useTranslations('home.collections');
  const tTypes = useTranslations('types');
  const format = useFormatter();
  const { Icon, halo } = COLLECTION_STYLE[type];

  return (
    <Link
      href={collectionHref(type)}
      data-testid={`collection-tile-${type}`}
      className="group relative isolate flex h-full flex-col overflow-hidden rounded-pssfp-card border border-pssfp-lavande/35 bg-white p-5 sm:min-h-[11.5rem] sm:p-6 shadow-pssfp-soft transition duration-300 ease-pssfp-out-expo hover:border-pssfp-lavande hover:shadow-pssfp-elevated focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-prune focus-visible:ring-offset-2 motion-safe:hover:-translate-y-1 motion-reduce:transition-none"
    >
      <span aria-hidden="true" className={`absolute -right-12 -top-12 -z-10 h-36 w-36 rounded-full transition-transform duration-500 ease-pssfp-out-expo motion-safe:group-hover:scale-110 ${halo}`} />
      <div className="flex items-start justify-between gap-4">
        <span aria-hidden="true" className="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-pssfp-mist text-pssfp-prune ring-1 ring-pssfp-lavande/30">
          <Icon size={20} strokeWidth={1.75} />
        </span>
        <ArrowUpRight
          size={20}
          strokeWidth={1.75}
          aria-hidden="true"
          className="text-pssfp-prune-light transition-transform duration-300 ease-pssfp-out-expo motion-safe:group-hover:-translate-y-0.5 motion-safe:group-hover:translate-x-0.5"
        />
      </div>
      <div className="mt-auto pt-5 sm:pt-8">
        <h3 className="font-heading text-[1.75rem] font-semibold leading-tight text-pssfp-prune-dark [overflow-wrap:anywhere] group-hover:text-pssfp-prune">{tTypes(`${type}.other`)}</h3>
        <div className="mt-1.5 flex flex-wrap items-end justify-between gap-x-4 gap-y-2">
          <span className="text-sm text-pssfp-graphite-light">{t(`descriptions.${type}`)}</span>
          <span className="inline-flex items-baseline gap-1.5 rounded-full bg-pssfp-mist px-2.5 py-0.5 font-ui text-xs text-pssfp-prune-dark">
            <span data-testid="collection-count" className="font-semibold [font-variant-numeric:lining-nums_tabular-nums]">
              {format.number(count)}
            </span>
            <span>{t('documents', { count })}</span>
          </span>
        </div>
      </div>
    </Link>
  );
}
