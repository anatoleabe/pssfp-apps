import { test, expect } from '@playwright/test';
import { citationAuthors, citationTitle } from '../../lib/citation';

test.describe('Citation — nettoyage des valeurs', () => {
  test('citationTitle retire la ponctuation finale avant le « . » du gabarit', () => {
    expect(citationTitle('Labore recusandae ut magnam ad et.')).toBe('Labore recusandae ut magnam ad et');
    expect(citationTitle('  Titre ;  ')).toBe('Titre');
    expect(citationTitle('Titre…')).toBe('Titre…');
    expect(citationTitle('Pourquoi réformer ?')).toBe('Pourquoi réformer ?');
    expect(citationTitle('Sans ponctuation')).toBe('Sans ponctuation');
  });

  test('citationAuthors garde le point des initiales, retire séparateurs et points doublés', () => {
    expect(citationAuthors(['Dupont J.', 'Martin B.,', ' Ngo C.. '])).toBe('Dupont J., Martin B., Ngo C.');
    expect(citationAuthors(['  ', 'Seul A'])).toBe('Seul A');
    expect(citationAuthors([])).toBe('');
  });
});
