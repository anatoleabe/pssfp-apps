import { expect, test } from '@playwright/test';
import { contenuEnveloppe } from '../../lib/api/enveloppe';

/*
 * Lecture de l'enveloppe Laravel `{ data: … }`. Une API qui répond
 * `{ data: null }` (aucun calendrier publié, aucun mémoire enregistré) doit
 * donner `null`, pas l'enveloppe elle-même : sinon la page lit
 * `calendrier.evenements` sur `{ data: null }` et plante (78 erreurs « evenements
 * is not iterable » et 19 « pourcentage » en production au 13/11/2026).
 */
test.describe('Enveloppe des réponses API', () => {
  test('rend null quand l’API répond { data: null }', () => {
    expect(contenuEnveloppe({ data: null })).toBeNull();
  });

  test('rend le contenu de data, objet ou liste', () => {
    expect(contenuEnveloppe({ data: { evenements: [] } })).toEqual({ evenements: [] });
    expect(contenuEnveloppe({ data: [], meta: { total: 0 } })).toEqual([]);
  });

  test('rend la réponse telle quelle quand elle n’est pas enveloppée', () => {
    expect(contenuEnveloppe({ token: 'x' })).toEqual({ token: 'x' });
    expect(contenuEnveloppe([1, 2])).toEqual([1, 2]);
    expect(contenuEnveloppe(null)).toBeNull();
  });
});
