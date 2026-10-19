import type { ReactNode } from 'react';
import { Eyebrow } from '@/components/home/Eyebrow';
import { Breadcrumbs, type BreadcrumbItem } from './Breadcrumbs';

/** Décor des bandeaux prune (dégradé, halo, arcs concentriques en écho au logo) : purement décoratif. */
export function BandDecor(): JSX.Element {
  return (
    <div aria-hidden="true" className="pointer-events-none absolute inset-0 -z-10">
      <div className="absolute inset-0 bg-[linear-gradient(116deg,#211429_0%,#2E1C40_55%,#40285A_100%)]" />
      <div className="absolute -right-32 -top-56 h-[30rem] w-[30rem] rounded-full border border-white/[0.07] shadow-[0_0_0_4rem_rgba(255,255,255,0.025),0_0_0_8rem_rgba(255,255,255,0.018)]" />
      <div className="absolute -right-10 -top-24 h-[24rem] w-[24rem] rounded-full bg-[radial-gradient(closest-side,rgba(165,146,189,0.22),transparent)]" />
    </div>
  );
}

interface PageHeaderProps {
  /** `id` du h1 (cible de `aria-labelledby`). */
  titleId: string;
  title: string;
  eyebrow: string;
  breadcrumbs: BreadcrumbItem[];
  /** Sous le titre : compte, chapeau… */
  meta?: ReactNode;
  /** Colonne de droite (≥ lg) ou sous le titre : recherche persistante. */
  aside?: ReactNode;
  /** Largeur de la colonne de droite. */
  asideWidth?: 'compact' | 'wide';
  testId?: string;
}

/**
 * Bandeau des pages intérieures, dans la continuité du héros de l'accueil : fond prune « deep »,
 * fil d'Ariane clair, sur-titre or, titre Cormorant blanc et recherche persistante.
 */
export function PageHeader({ titleId, title, eyebrow, breadcrumbs, meta, aside, asideWidth = 'compact', testId }: PageHeaderProps): JSX.Element {
  const columns = asideWidth === 'wide' ? 'lg:grid-cols-[minmax(0,1fr)_minmax(0,40rem)]' : 'lg:grid-cols-[minmax(0,1fr)_minmax(0,28rem)]';
  return (
    <section data-testid={testId} aria-labelledby={titleId} className="relative isolate overflow-hidden bg-pssfp-prune-deep text-white">
      <BandDecor />
      <div className="mx-auto max-w-7xl px-4 pb-10 pt-7 sm:px-6 sm:pb-12 lg:pb-14 lg:pt-9">
        <Breadcrumbs tone="dark" items={breadcrumbs} />
        <div className={`mt-8 grid gap-8 lg:items-end lg:gap-16 ${aside ? columns : ''}`}>
          <div className="min-w-0">
            <Eyebrow tone="light">{eyebrow}</Eyebrow>
            <h1 id={titleId} className="mt-4 text-balance font-heading text-pssfp-h1 font-semibold hyphens-auto [overflow-wrap:anywhere] lg:text-[3.75rem] lg:leading-[1.02]">
              {title}
            </h1>
            {meta && <div className="mt-4">{meta}</div>}
          </div>
          {aside && <div className="min-w-0">{aside}</div>}
        </div>
      </div>
    </section>
  );
}
