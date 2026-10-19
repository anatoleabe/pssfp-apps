import type { ReactNode } from 'react';
import { Eyebrow } from './Eyebrow';

interface SectionHeadingProps {
  /** `id` du titre (cible de `aria-labelledby` de la section). */
  id: string;
  eyebrow: string;
  title: string;
  intro?: string;
  /** Lien secondaire aligné à droite (« Tous les thèmes », etc.). */
  action?: ReactNode;
}

/** En-tête de section éditorial : sur-titre, titre Cormorant, chapeau et action facultative. */
export function SectionHeading({ id, eyebrow, title, intro, action }: SectionHeadingProps): JSX.Element {
  return (
    <div className="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between sm:gap-10">
      <div className="min-w-0 max-w-2xl">
        <Eyebrow>{eyebrow}</Eyebrow>
        <h2 id={id} className="mt-4 text-balance font-heading text-pssfp-h2 font-semibold text-pssfp-prune-dark">
          {title}
        </h2>
        {intro && <p className="mt-3 text-pssfp-graphite-light">{intro}</p>}
      </div>
      {action && <div className="shrink-0 sm:pb-1">{action}</div>}
    </div>
  );
}
