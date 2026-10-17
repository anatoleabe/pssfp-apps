'use client';

import { useCallback, useEffect, useState } from 'react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useTranslations } from 'next-intl';

const BUTTON =
  'inline-flex h-11 w-11 items-center justify-center rounded-full border border-pssfp-prune/25 bg-white text-pssfp-prune shadow-pssfp-soft transition-colors hover:border-pssfp-prune hover:bg-pssfp-prune hover:text-white active:bg-pssfp-prune-dark disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:border-pssfp-prune/25 disabled:hover:bg-white disabled:hover:text-pssfp-prune focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-pssfp-or focus-visible:ring-offset-2';

/** Boutons précédent / suivant du bandeau : défilement d'une largeur de carte (doux sauf mouvement réduit). */
export function MostReadControls({ listId }: { listId: string }): JSX.Element {
  const t = useTranslations('home.mostRead');
  const [edges, setEdges] = useState({ start: true, end: false });

  useEffect(() => {
    const list = document.getElementById(listId);
    if (!list) return undefined;
    const update = (): void => {
      const max = list.scrollWidth - list.clientWidth;
      setEdges({ start: list.scrollLeft <= 1, end: list.scrollLeft >= max - 1 });
    };
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
      const list = document.getElementById(listId);
      const item = list?.querySelector('li');
      if (!list || !item) return;
      const gap = Number.parseFloat(getComputedStyle(list).columnGap) || 0;
      const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      list.scrollBy({ left: direction * (item.getBoundingClientRect().width + gap), behavior: reduced ? 'auto' : 'smooth' });
    },
    [listId],
  );

  return (
    <div className="flex shrink-0 gap-2">
      <button type="button" aria-controls={listId} aria-label={t('previous')} disabled={edges.start} onClick={() => scroll(-1)} className={BUTTON}>
        <ChevronLeft size={20} aria-hidden="true" />
      </button>
      <button type="button" aria-controls={listId} aria-label={t('next')} disabled={edges.end} onClick={() => scroll(1)} className={BUTTON}>
        <ChevronRight size={20} aria-hidden="true" />
      </button>
    </div>
  );
}
