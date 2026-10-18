import { test, expect } from '@playwright/test';
import { filtersToSearchParams, searchParamsToFilters } from '../../lib/routes';

test.describe('searchParamsToFilters', () => {
  test('garde les valeurs reconnues', () => {
    const f = searchParamsToFilters(new URLSearchParams('type=memoire&acces=libre&tri=populaire&promotion=12&annee_min=2010&annee_max=2024&specialite=fiscalite&langue=fr&mot_cle=tva'));
    expect(f).toEqual({ type: 'memoire', acces: 'libre', tri: 'populaire', promotion: 12, annee_min: 2010, annee_max: 2024, specialite: 'fiscalite', langue: 'fr', mot_cle: 'tva' });
  });

  test('ignore les type, acces et tri inconnus', () => {
    expect(searchParamsToFilters(new URLSearchParams('type=roman&acces=gratuit&tri=hasard'))).toEqual({});
    expect(searchParamsToFilters({ type: 'MEMOIRE', acces: '', tri: undefined })).toEqual({});
  });

  test('ignore les nombres non entiers ou hors bornes', () => {
    const bad = ['abc', '1.5', '-3', '0', '1e3', ''];
    for (const v of bad) {
      expect(searchParamsToFilters({ promotion: v, annee_min: v, annee_max: v })).toEqual({});
    }
    expect(searchParamsToFilters({ promotion: '100' })).toEqual({});
    expect(searchParamsToFilters({ promotion: '99' })).toEqual({ promotion: 99 });
    expect(searchParamsToFilters({ annee_min: '1899', annee_max: '2101' })).toEqual({});
    expect(searchParamsToFilters({ annee_min: '1900', annee_max: '2100' })).toEqual({ annee_min: 1900, annee_max: 2100 });
  });

  test('ignore les slugs invalides', () => {
    for (const v of ['Fiscalité', 'a b', '../etc', '<script>', 'x'.repeat(121), 'UPPER']) {
      expect(searchParamsToFilters({ specialite: v, langue: v, mot_cle: v })).toEqual({});
    }
  });

  test('ignore les langues inconnues de l\'API (sinon 422)', () => {
    expect(searchParamsToFilters({ langue: 'xx' })).toEqual({});
    for (const v of ['fr', 'en', 'ar', 'autre']) expect(searchParamsToFilters({ langue: v })).toEqual({ langue: v });
  });

  test('prend la première valeur d\'un tableau', () => {
    expect(searchParamsToFilters({ type: ['these', 'memoire'], promotion: ['7', '8'], mot_cle: ['tva', 'budget'] })).toEqual({ type: 'these', promotion: 7, mot_cle: 'tva' });
    expect(searchParamsToFilters(new URLSearchParams('type=these&type=memoire'))).toEqual({ type: 'these' });
  });

  test('aller-retour avec filtersToSearchParams', () => {
    const f = { type: 'cours', promotion: 3, tri: 'recent' } as const;
    expect(searchParamsToFilters(filtersToSearchParams(f))).toEqual(f);
  });
});
