'use client';

import { useCallback, useEffect, useState } from 'react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useTranslations } from 'next-intl';

const BUTTON =
  'inline-flex h-11 w-11 items-center justify-center rounded-full border border-pssfp-prune/25 bg-white text-pssfp-prune shadow-pssfp-soft transition-colors hover:border-pssfp-prune hover:bg-pssfp-prune hover:text-white active:bg-pssfp-prune-dark aria-disabled:cursor-not-allowed aria-disabled:opacity-40 aria-disabled:hover:border-pssfp-prune/25 aria-disabled:hover:bg-white aria-disabled:hover:text-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-or focus-visible:ring-offset-2';

interface Edges {
  start: boolean;
  end: boolean;
}

/** Bords atteints ; une liste qui ne déborde pas est à la fois au début et à la fin. */
function readEdges(list: HTMLElement): Edges {
  const max = list.scrollWidth - list.clientWidth;
  return { start: list.scrollLeft <= 1, end: max <= 1 || list.scrollLeft >= max - 1 };
}

/**
 * Boutons précédent / suivant du bandeau : défilement d'une largeur de carte (doux sauf mouvement réduit).
 * Aux extrémités, `aria-disabled` : le bouton reste focalisable (le focus ne saute pas) mais ne fait rien.
 */
export function MostReadControls({ listId }: { listId: string }): JSX.Element {
  const t = useTranslations('home.mostRead');
  // Avant hydratation : rien ne permet de reculer ; « suivant » est recalculé dès le montage.
  const [edges, setEdges] = useState<Edges>({ start: true, end: false });

  useEffect(() => {
    const list = document.getElementById(listId);
    if (!list) return undefined;
    const update = (): void => setEdges(readEdges(list));
    update();
    list.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);
    return () => {
      list.removeEventListener('scroll', update);
      window.removeEventListener('resize', update);
    };
  }, [listId]);

  const scroll = useCallback(
    (direction: 1 | -1): void => {
      if ((direction === -1 && edges.start) || (direction === 1 && edges.end)) return;
      const list = document.getElementById(listId);
      const item = list?.querySelector('li');
      if (!list || !item) return;
      const gap = Number.parseFloat(getComputedStyle(list).columnGap) || 0;
      const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      list.scrollBy({ left: direction * (item.getBoundingClientRect().width + gap), behavior: reduced ? 'auto' : 'smooth' });
    },
    [edges, listId],
  );

  return (
    <div className="flex shrink-0 gap-2">
      <button type="button" aria-controls={listId} aria-label={t('previous')} aria-disabled={edges.start} onClick={() => scroll(-1)} className={BUTTON}>
        <ChevronLeft size={20} aria-hidden="true" />
      </button>
      <button type="button" aria-controls={listId} aria-label={t('next')} aria-disabled={edges.end} onClick={() => scroll(1)} className={BUTTON}>
        <ChevronRight size={20} aria-hidden="true" />
      </button>
    </div>
  );
}
