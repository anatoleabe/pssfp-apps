export type PageItem = number | 'ellipsis';

const toPositiveInt = (n: number): number => (Number.isFinite(n) ? Math.max(1, Math.floor(n)) : 1);

/**
 * Numéros affichés : 1 … p-1 p p+1 … dernière. Un trou d'une seule page
 * affiche ce numéro plutôt qu'une ellipse (qui ne ferait rien gagner).
 */
export function paginationItems(page: number, lastPage: number): PageItem[] {
  const last = toPositiveInt(lastPage);
  const current = Math.min(toPositiveInt(page), last);
  const wanted = Array.from(new Set([1, current - 1, current, current + 1, last]))
    .filter((p) => p >= 1 && p <= last)
    .sort((a, b) => a - b);
  return wanted.flatMap((p, i): PageItem[] => {
    const prev = wanted[i - 1];
    const gap = prev === undefined ? 1 : p - prev;
    if (gap === 1) return [p];
    return gap === 2 ? [p - 1, p] : ['ellipsis', p];
  });
}
