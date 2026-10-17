'use client';

import { useSearchParams } from 'next/navigation';
import { useEffect, useRef } from 'react';

interface ResultsFocusProps {
  /** `id` du titre des résultats (focalisable : `tabIndex={-1}`). */
  targetId: string;
}

/** Sélecteur de la pagination (attribut posé par `Pagination`). */
const PAGINATION = '[data-pagination]';

/**
 * Collections : après une navigation client qui change les paramètres (puce retirée, « Retirer les
 * filtres », pagination, réinitialisation de l'état vide), le focus passe au titre des résultats
 * s'il est perdu sur `body` (lien activé démonté) ou resté dans la pagination (nouvelle page à lire
 * depuis le haut de la liste). Au premier chargement, rien n'est déplacé. Après Précédent/Suivant,
 * le focus est posé sans défilement : la position restaurée par le navigateur est respectée.
 *
 * La page n'est pas remontée quand seuls les paramètres changent (clé de segment sans paramètres) :
 * les références survivent d'une navigation à l'autre.
 */
export function ResultsFocus({ targetId }: ResultsFocusProps): null {
  const key = useSearchParams().toString();
  const previousKey = useRef<string | undefined>(undefined);
  const fromHistory = useRef(false);

  useEffect(() => {
    const onPopState = (): void => {
      fromHistory.current = true;
    };
    window.addEventListener('popstate', onPopState);
    return () => window.removeEventListener('popstate', onPopState);
  }, []);

  useEffect(() => {
    const changed = previousKey.current !== undefined && previousKey.current !== key;
    previousKey.current = key;
    const preventScroll = fromHistory.current;
    fromHistory.current = false;
    if (!changed) return;
    const active = document.activeElement;
    const lost = active === null || active === document.body || active.closest(PAGINATION) !== null;
    if (lost) document.getElementById(targetId)?.focus({ preventScroll });
  }, [key, targetId]);

  return null;
}
