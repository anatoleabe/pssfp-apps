import { test, expect } from '@playwright/test';
import { paginationItems } from '../../lib/pagination';
import { fileSizeParts, formatPrice } from '../../lib/format';

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

test('formats : prix et taille de fichier en fr-FR', () => {
  expect(formatPrice(2500)).toMatch(/^2\s500$/);
  expect(fileSizeParts(512)).toEqual({ value: '1', unit: 'ko' });
  expect(fileSizeParts(200 * 1024)).toEqual({ value: '200', unit: 'ko' });
  expect(fileSizeParts(2.4 * 1024 * 1024)).toEqual({ value: '2,4', unit: 'mo' });
});
