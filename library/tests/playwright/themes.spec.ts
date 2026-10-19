import { test, expect } from '@playwright/test';
import type { MotCle } from '../../lib/api/types';
import { themeHref, themesHref } from '../../lib/routes';
import { buildThemeTree, childrenOf, collectionThemes, countedChildren, countsKnown, featuredThemes, themesCount } from '../../lib/themes';

/** Thésaurus fictif, à la forme de `GET /v1/library/mots-cles` (racines, niveau intermédiaire). */
const TREE: MotCle[] = [
  { slug: 'themes', label: 'Thèmes', parent_slug: null },
  { slug: 'fiscalite', label: 'Fiscalité', parent_slug: 'themes' },
  { slug: 'marches-publics', label: 'Marchés publics', parent_slug: 'themes' },
  { slug: 'gestion-budgetaire', label: 'Gestion budgétaire', parent_slug: 'themes' },
  { slug: 'autres-ouvrages', label: 'Autres ouvrages', parent_slug: 'themes' },
  { slug: 'institutions', label: 'Institutions', parent_slug: null },
  { slug: 'institutions-internationales', label: 'Institutions internationales', parent_slug: 'institutions' },
  { slug: 'fmi', label: 'Fonds monétaire international (FMI)', parent_slug: 'institutions-internationales' },
  { slug: 'bad', label: 'Banque africaine de développement (BAD)', parent_slug: 'institutions-internationales' },
  { slug: 'institutions-nationales', label: 'Institutions nationales', parent_slug: 'institutions' },
  { slug: 'minfi', label: 'Ministère des Finances (MINFI)', parent_slug: 'institutions-nationales' },
  { slug: 'types-de-textes', label: 'Types de textes', parent_slug: null },
  { slug: 'decrets', label: 'Décrets', parent_slug: 'types-de-textes' },
  { slug: 'lois', label: 'Lois', parent_slug: 'types-de-textes' },
];

const COUNTS: Record<string, number> = {
  themes: 60, fiscalite: 30, 'marches-publics': 0, 'gestion-budgetaire': 12, 'autres-ouvrages': 18,
  institutions: 5, 'institutions-internationales': 5, fmi: 5, bad: 0, 'institutions-nationales': 0, minfi: 0,
  'types-de-textes': 40, decrets: 25, lois: 15,
};
const COUNTED: MotCle[] = TREE.map((m) => ({ ...m, documents_count: COUNTS[m.slug] ?? 0 }));

test.describe('Thèmes — utilitaires', () => {
  test('routes : /themes, /themes/{slug} et page', () => {
    expect(themesHref).toBe('/themes');
    expect(themeHref('fiscalite')).toBe('/themes/fiscalite');
    expect(themeHref('fiscalite', 1)).toBe('/themes/fiscalite');
    expect(themeHref('fiscalite', 3)).toBe('/themes/fiscalite?page=3');
  });

  test('comptes absents (API antérieure) : tout est affiché, sans nombre', () => {
    expect(countsKnown(TREE)).toBe(false);
    const tree = buildThemeTree(TREE);
    expect(tree.map((n) => n.slug)).toEqual(['themes', 'institutions', 'types-de-textes']);
    expect(tree[0]!.children.map((n) => n.label)).toEqual(['Autres ouvrages', 'Fiscalité', 'Gestion budgétaire', 'Marchés publics']);
    expect(tree[0]!.children.every((n) => n.count === null)).toBe(true);
    // Niveau intermédiaire conservé.
    expect(tree[1]!.children.map((n) => n.slug)).toEqual(['institutions-internationales', 'institutions-nationales']);
    expect(tree[1]!.children[0]!.children.map((n) => n.slug)).toEqual(['bad', 'fmi']);
    expect(themesCount(TREE)).toBe(4);
  });

  test('comptes fournis : mots-clés à 0 masqués, branches vides retirées', () => {
    const tree = buildThemeTree(COUNTED);
    const themes = tree.find((n) => n.slug === 'themes')!;
    expect(themes.children.map((n) => [n.slug, n.count])).toEqual([
      ['autres-ouvrages', 18],
      ['fiscalite', 30],
      ['gestion-budgetaire', 12],
    ]);
    const institutions = tree.find((n) => n.slug === 'institutions')!;
    expect(institutions.children.map((n) => n.slug)).toEqual(['institutions-internationales']);
    expect(institutions.children[0]!.children.map((n) => n.slug)).toEqual(['fmi']);
    expect(themesCount(COUNTED)).toBe(3);
  });

  test('racine absente ou sans enfant visible : pas de groupe', () => {
    const tree = buildThemeTree(COUNTED.filter((m) => m.slug !== 'types-de-textes' && m.parent_slug !== 'types-de-textes'));
    expect(tree.map((n) => n.slug)).toEqual(['themes', 'institutions']);
    expect(buildThemeTree([])).toEqual([]);
  });

  test('thèmes mis en avant : ordre du brief sans comptes, par nombre sinon', () => {
    expect(featuredThemes(TREE).map((m) => m.slug)).toEqual(['gestion-budgetaire', 'fiscalite', 'marches-publics', 'autres-ouvrages']);
    expect(featuredThemes(COUNTED).map((m) => m.slug)).toEqual(['fiscalite', 'autres-ouvrages', 'gestion-budgetaire']);
    expect(featuredThemes(TREE, 2)).toHaveLength(2);
  });

  test('puces de collection : feuilles comptées, par nombre décroissant, aucune sans comptes', () => {
    expect(collectionThemes(TREE)).toEqual([]);
    expect(collectionThemes(COUNTED).map((m) => m.slug)).toEqual(['fiscalite', 'decrets', 'autres-ouvrages', 'lois', 'gestion-budgetaire', 'fmi']);
    expect(collectionThemes(COUNTED, 2).map((m) => m.slug)).toEqual(['fiscalite', 'decrets']);
  });

  test('sous-thèmes et types de textes comptés', () => {
    expect(childrenOf(TREE, 'institutions-internationales').map((m) => m.slug)).toEqual(['bad', 'fmi']);
    expect(childrenOf(COUNTED, 'institutions-internationales').map((m) => m.slug)).toEqual(['fmi']);
    expect(countedChildren(TREE, 'types-de-textes')).toEqual([]);
    expect(countedChildren(COUNTED, 'types-de-textes').map((m) => m.slug)).toEqual(['decrets', 'lois']);
  });
});
