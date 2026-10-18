import { test, expect } from '@playwright/test';
import { paginationItems } from '../../lib/pagination';
import messages from '../../messages/fr.json';
import { PRICE_FORMAT, fileSizeFormat } from '../../lib/format';

test('pagination : 1 … p-1 p p+1 … last', () => {
  expect(paginationItems(5, 12)).toEqual([1, 'ellipsis', 4, 5, 6, 'ellipsis', 12]);
  expect(paginationItems(1, 1)).toEqual([1]);
  expect(paginationItems(1, 3)).toEqual([1, 2, 3]);
  expect(paginationItems(12, 12)).toEqual([1, 'ellipsis', 11, 12]);
  // Un seul numéro manquant : on l'affiche plutôt qu'une ellipse.
  expect(paginationItems(4, 12)).toEqual([1, 2, 3, 4, 5, 'ellipsis', 12]);
});

test('pagination : bornes et valeurs invalides ramenées dans l\'intervalle', () => {
  expect(paginationItems(0, 5)).toEqual([1, 2, 'ellipsis', 5]);
  expect(paginationItems(99, 5)).toEqual([1, 'ellipsis', 4, 5]);
  expect(paginationItems(Number.NaN, 0)).toEqual([1]);
});

test('formats : prix en XAF et taille de fichier par Intl (fr-FR), sans unité en dur', () => {
  const fr = (value: number, options: Intl.NumberFormatOptions): string => new Intl.NumberFormat('fr-FR', options).format(value);
  // Sortie réelle d'Intl : espace fine insécable (milliers) puis espace insécable avant « FCFA ».
  expect(fr(2500, PRICE_FORMAT)).toBe('2\u202f500\u00a0FCFA');
  expect(fr(2500.4, PRICE_FORMAT)).toBe('2\u202f500\u00a0FCFA');
  const size = (bytes: number): string => {
    const { value, format } = fileSizeFormat(bytes);
    return fr(value, format);
  };
  expect(size(512)).toBe('1\u202fko');
  expect(size(200 * 1024)).toBe('200\u202fko');
  expect(size(2.4 * 1024 * 1024)).toBe('2,4\u202fMo');
});

test('messages : prix sans devise en dur, clés inutilisées supprimées', () => {
  expect(messages.access.payantPrice).not.toContain('FCFA');
  expect(messages.access.action.paidPrice).not.toContain('FCFA');
  const has = (path: string): boolean => path.split('.').reduce<unknown>((node, k) => (node && typeof node === 'object' ? (node as Record<string, unknown>)[k] : undefined), messages) !== undefined;
  for (const key of ['home.title', 'home.intro', 'document.authors', 'document.year', 'search.minLength', 'filters.type', 'filters.specialite', 'states.loading', 'access.action.sizeKo', 'access.action.sizeMo']) {
    expect(has(key), key).toBe(false);
  }
});
