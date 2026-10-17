import Link from 'next/link';
import { ArrowUpRight } from 'lucide-react';
import { getFormatter, getTranslations } from 'next-intl/server';
import { AccessBadge } from '@/components/document/AccessBadge';
import type { DocumentType, LibraryStats } from '@/lib/api/types';
import { collectionHref } from '@/lib/routes';
import { Eyebrow } from './Eyebrow';

interface TileSpec {
  type: DocumentType;
  /** Fond, texte, bordure et halo au survol. */
  surface: string;
  /** Teinte du compteur et des libellés secondaires (contraste ≥ 4,5:1 sur la surface). */
  muted: string;
  reserved?: boolean;
}

/*
 * Contrastes : blanc / prune 11,18:1 · blanc/85 % / prune ≥ 8:1 · blanc / pétrole 12,18:1 ·
 * prune / blanc 11,18:1 · graphite / blanc 11,03:1 · prune foncé / lavande 4,79:1 ·
 * prune foncé / or 6,52:1.
 */
const TILES: readonly TileSpec[] = [
  { type: 'these', surface: 'bg-pssfp-bleu-petrole text-white hover:shadow-pssfp-glow-petrole', muted: 'text-white/85' },
  { type: 'texte_loi', surface: 'border border-pssfp-lavande/50 bg-white text-pssfp-prune hover:border-pssfp-prune/40 hover:shadow-pssfp-elevated', muted: 'text-pssfp-graphite' },
  { type: 'ouvrage_numerique', surface: 'border border-pssfp-lavande/50 bg-white text-pssfp-prune hover:border-pssfp-prune/40 hover:shadow-pssfp-elevated', muted: 'text-pssfp-graphite' },
  { type: 'cours', surface: 'bg-pssfp-lavande text-pssfp-prune-dark hover:shadow-pssfp-glow-prune', muted: 'text-pssfp-prune-dark', reserved: true },
];

const TILE =
  'group relative flex h-full flex-col justify-between gap-6 overflow-hidden rounded-pssfp-card p-6 transition duration-300 ease-pssfp-out-expo motion-safe:hover:-translate-y-1 motion-reduce:transition-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-or focus-visible:ring-offset-2 focus-visible:ring-offset-pssfp-ivoire';

function TileArrow(): JSX.Element {
  return (
    <ArrowUpRight
      size={22}
      strokeWidth={1.75}
      aria-hidden="true"
      className="shrink-0 transition-transform duration-300 ease-pssfp-out-expo motion-safe:group-hover:-translate-y-0.5 motion-safe:group-hover:translate-x-0.5"
    />
  );
}

export async function CollectionsMosaic({ stats }: { stats: LibraryStats }): Promise<JSX.Element> {
  const t = await getTranslations('home.collections');
  const tTypes = await getTranslations('types');
  const format = await getFormatter();
  const count = (type: DocumentType): number => stats.by_type[type] ?? 0;
  const memoires = count('memoire');
  const promotions = stats.promotions_count ?? 0;

  return (
    <section id="collections" aria-labelledby="collections-title" className="scroll-mt-20 py-16 sm:py-20 lg:py-24">
      <div className="mx-auto max-w-7xl px-4 sm:px-6">
        <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] lg:items-end lg:gap-16">
          <div>
            <Eyebrow>{t('eyebrow')}</Eyebrow>
            <h2 id="collections-title" className="mt-4 font-heading text-pssfp-h2 font-semibold text-pssfp-prune">
              {t('title')}
            </h2>
          </div>
          <p className="max-w-xl text-pssfp-body text-pssfp-graphite lg:justify-self-end">{t('intro')}</p>
        </div>

        <ul className="mt-10 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-rows-[repeat(2,minmax(11rem,auto))] lg:grid-cols-4 lg:gap-5">
          {memoires > 0 && (
            <li className="sm:col-span-2 lg:row-span-2">
              <Link
                href={collectionHref('memoire')}
                data-testid="collection-tile-memoire"
                className={`${TILE} min-h-[17rem] bg-pssfp-prune p-7 text-white shadow-pssfp-elevated hover:shadow-pssfp-glow-prune sm:p-9`}
              >
                {/* Dos de reliures : filets verticaux décoratifs. */}
                <span
                  aria-hidden="true"
                  className="pointer-events-none absolute inset-y-0 right-0 w-1/2 bg-[repeating-linear-gradient(90deg,rgba(255,255,255,0.06)_0_1px,transparent_1px_18px)] [mask-image:linear-gradient(to_left,black,transparent)]"
                />
                <span className="relative">
                  <span className="block font-ui text-xs font-medium uppercase tracking-[0.18em] text-pssfp-or">{t('collection')}</span>
                  <h3 className="mt-3 font-heading text-[2.75rem] font-semibold leading-none sm:text-6xl">{tTypes('memoire.other')}</h3>
                </span>
                <span className="relative flex items-end justify-between gap-4">
                  <span className="flex flex-col gap-1">
                    <span data-testid="collection-count" className="font-heading text-6xl font-semibold leading-none tabular-nums text-white sm:text-7xl">
                      {format.number(memoires)}
                    </span>
                    <span className="font-ui text-sm text-white/85">{promotions > 0 ? t('memoireMetaPromotions', { count: memoires, promotions }) : t('memoireMeta', { count: memoires })}</span>
                  </span>
                  <TileArrow />
                </span>
              </Link>
            </li>
          )}

          {TILES.filter((tile) => count(tile.type) > 0).map((tile) => (
            <li key={tile.type}>
              <Link href={collectionHref(tile.type)} data-testid={`collection-tile-${tile.type}`} className={`${TILE} min-h-[11rem] ${tile.surface}`}>
                <span className="flex items-start justify-between gap-3">
                  <h3 className="min-w-0 font-heading text-3xl font-semibold leading-tight [overflow-wrap:anywhere]">{tTypes(`${tile.type}.other`)}</h3>
                  <TileArrow />
                </span>
                <span className="flex flex-wrap items-end justify-between gap-3">
                  <span className={`flex items-baseline gap-2 ${tile.muted}`}>
                    <span data-testid="collection-count" className="font-heading text-4xl font-semibold leading-none tabular-nums">
                      {format.number(count(tile.type))}
                    </span>
                    <span className="font-ui text-sm">{t('documents', { count: count(tile.type) })}</span>
                  </span>
                  {tile.reserved && <AccessBadge mode="reserve" />}
                </span>
              </Link>
            </li>
          ))}

          {stats.paid_count > 0 && (
            <li className="sm:col-span-2 lg:col-span-4">
              <Link
                href={collectionHref('ouvrage_numerique', { acces: 'payant' })}
                data-testid="collection-tile-premium"
                className={`${TILE} bg-gradient-to-br from-pssfp-or to-pssfp-or-light text-pssfp-prune-dark hover:shadow-pssfp-glow-or sm:flex-row sm:items-center sm:gap-10 lg:px-9 lg:py-7`}
              >
                <span className="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:gap-6">
                  <h3 className="font-heading text-3xl font-semibold leading-tight sm:text-4xl">{t('premiumTitle')}</h3>
                  <span className="font-ui text-sm font-medium uppercase tracking-[0.16em]">{t('premiumBody')}</span>
                </span>
                <span className="flex items-center justify-between gap-4 sm:ml-auto">
                  <span className="font-ui text-sm">{t('premiumCount', { count: stats.paid_count })}</span>
                  <TileArrow />
                </span>
              </Link>
            </li>
          )}
        </ul>
      </div>
    </section>
  );
}
