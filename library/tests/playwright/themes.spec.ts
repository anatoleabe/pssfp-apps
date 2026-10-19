import { test, expect, type Locator, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
// Même base que l'app (playwright.config.ts charge .env.local comme `next dev`).
import { API_BASE_URL as API } from '../../lib/api/client';
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
    // « Autres ouvrages » (fourre-tout) n'est pas compté comme thème de recherche.
    expect(themesCount(TREE)).toBe(3);
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
    expect(themesCount(COUNTED)).toBe(2);
  });

  test('racine absente ou sans enfant visible : pas de groupe', () => {
    const tree = buildThemeTree(COUNTED.filter((m) => m.slug !== 'types-de-textes' && m.parent_slug !== 'types-de-textes'));
    expect(tree.map((n) => n.slug)).toEqual(['themes', 'institutions']);
    expect(buildThemeTree([])).toEqual([]);
  });

  test('thèmes mis en avant : ordre du brief sans comptes, par nombre sinon, sans fourre-tout', () => {
    expect(featuredThemes(TREE).map((m) => m.slug)).toEqual(['gestion-budgetaire', 'fiscalite', 'marches-publics']);
    expect(featuredThemes(COUNTED).map((m) => m.slug)).toEqual(['fiscalite', 'gestion-budgetaire']);
    const withGeneralites: MotCle[] = [...COUNTED, { slug: 'generalites', label: 'Généralités', parent_slug: 'themes', documents_count: 99 }];
    expect(featuredThemes(withGeneralites).map((m) => m.slug)).not.toContain('generalites');
    expect(themesCount(withGeneralites)).toBe(2);
    expect(featuredThemes(TREE, 2)).toHaveLength(2);
  });

  test('puces de collection : mots-clés comptés (niveaux intermédiaires compris), par nombre décroissant, aucune sans comptes', () => {
    expect(collectionThemes(TREE)).toEqual([]);
    expect(collectionThemes(COUNTED).map((m) => m.slug)).toEqual(['fiscalite', 'decrets', 'autres-ouvrages', 'lois', 'gestion-budgetaire', 'fmi', 'institutions-internationales']);
    expect(collectionThemes(COUNTED, 2).map((m) => m.slug)).toEqual(['fiscalite', 'decrets']);
  });

  test('sous-thèmes et types de textes comptés', () => {
    expect(childrenOf(TREE, 'institutions-internationales').map((m) => m.slug)).toEqual(['bad', 'fmi']);
    expect(childrenOf(COUNTED, 'institutions-internationales').map((m) => m.slug)).toEqual(['fmi']);
    expect(countedChildren(TREE, 'types-de-textes')).toEqual([]);
    expect(countedChildren(COUNTED, 'types-de-textes').map((m) => m.slug)).toEqual(['decrets', 'lois']);
  });
});

/** Composants des thèmes sur la page de démonstration (données fictives, sans backend). */
test.describe('Thèmes — composants (démonstration)', () => {
  const DEMO = '/dev/composants';

  test.beforeEach(async ({ page }) => {
    await page.route('**/library/documents/*/view', (route) => route.fulfill({ status: 204 }));
  });

  test('index avec comptes : trois groupes, comptes, thèmes vides masqués, niveau intermédiaire', async ({ page }) => {
    await page.goto(DEMO);
    const index = page.getByTestId('demo-theme-index-counted');
    await expect(index.locator('[data-testid^="theme-group-"]')).toHaveCount(3);
    await expect(index.getByRole('heading', { level: 2 })).toHaveText(['Thèmes', 'Institutions', 'Types de textes']);
    const fiscalite = index.getByTestId('theme-link-demo-fiscalite');
    await expect(fiscalite).toHaveAttribute('href', '/themes/demo-fiscalite');
    await expect(fiscalite).toContainText('1 250 documents');
    await expect(index.getByTestId('theme-link-demo-vide')).toHaveCount(0);
    // Institutions internationales : sous-titre lié à sa page, puis ses enfants.
    await expect(index.getByRole('heading', { level: 3, name: /Institutions internationales/ }).getByRole('link')).toHaveAttribute('href', '/themes/demo-internationales');
    await expect(index.getByTestId('theme-link-demo-fmi')).toContainText('12 documents');
    // Sommaire : ancres vers chaque groupe.
    await expect(index.getByRole('navigation', { name: 'Groupes de thèmes' }).getByRole('link')).toHaveCount(3);
  });

  test('index sans comptes (API antérieure) : tous les thèmes, aucun nombre', async ({ page }) => {
    await page.goto(DEMO);
    const index = page.getByTestId('demo-theme-index-uncounted');
    await expect(index.getByTestId('theme-link-demo-vide')).toBeVisible();
    await expect(index).not.toContainText(/\d+ documents?/);
    // Sans comptes, l'API n'agrège pas les sous-thèmes : pas de lien vers les pages de regroupement.
    await expect(index.getByRole('link', { name: /^Tous les documents/ })).toHaveCount(0);
    await expect(index.getByRole('heading', { level: 3, name: /Institutions internationales/ }).getByRole('link')).toHaveCount(0);
  });

  test('puces de collection : comptes, lien mot_cle, puce appliquée qui retire le filtre', async ({ page }) => {
    await page.goto(DEMO);
    const zone = page.getByTestId('demo-collection-themes');
    const chips = zone.getByRole('listitem').getByRole('link');
    // Mots-clés comptés par nombre décroissant, niveau intermédiaire compris.
    await expect(chips).toHaveText([/Fiscalité \(démo\)/, /Gestion budgétaire \(démo\)/, /Décrets \(démo\)/, /Institutions internationales \(démo\)/, /Organisation fictive A/]);
    await expect(chips.first()).toHaveAttribute('href', '/collections/texte_loi?mot_cle=demo-fiscalite&acces=libre');
    const active = zone.locator('[aria-current="true"]');
    await expect(active).toHaveCount(1);
    // Nom accessible : texte visible (libellé, nombre) d'abord, précision ensuite (WCAG 2.5.3).
    await expect(active).toHaveAccessibleName(/^Décrets \(démo\) 47 documents, thème appliqué/);
    await expect(chips.first()).toHaveAccessibleName(/^Fiscalité \(démo\) 1\s?250 documents, filtrer la collection/);
    await expect(active).toHaveAttribute('href', '/collections/texte_loi?acces=libre');
    await expect(zone.getByRole('link', { name: 'Tous les thèmes' })).toHaveAttribute('href', '/themes');
    // Sans comptes : aucune puce (rien ne dit quels mots-clés concernent la collection).
    await expect(page.getByTestId('demo-collection-themes-uncounted').getByTestId('collection-themes')).toHaveCount(0);
  });

  test('puces de collection : atteintes par Tab, focus visible', async ({ page }) => {
    await page.goto(DEMO);
    // Point de départ : le titre de la section, rendu focalisable par programme (tabindex=-1, hors ordre de
    // tabulation) ; la puce doit ensuite être atteinte par de vraies tabulations, jamais par `.focus()`.
    await startFrom(page.getByRole('heading', { level: 2, name: 'Thèmes d\'une collection' }));
    const chip = page.getByTestId('demo-collection-themes').getByRole('listitem').getByRole('link').first();
    const reached = await tabUntil(page, chip, 6);
    expect(reached, 'puce atteinte en 6 tabulations au plus').toBe(true);
    const ring = await chip.evaluate((el) => getComputedStyle(el).boxShadow);
    expect(ring).toContain('rgb(74, 46, 103)');
  });
});

/** Place le point de départ de la navigation séquentielle sur `el` (non focalisable par Tab). */
async function startFrom(el: Locator): Promise<void> {
  await el.evaluate((node) => {
    node.setAttribute('tabindex', '-1');
    (node as HTMLElement).focus();
  });
}

/** Appuie sur Tab jusqu'à ce que `target` ait le focus (au plus `max` fois) ; vrai si atteint. */
async function tabUntil(page: Page, target: Locator, max: number): Promise<boolean> {
  for (let i = 0; i < max; i += 1) {
    await page.keyboard.press('Tab');
    if (await target.evaluate((el) => el === document.activeElement)) return true;
  }
  return false;
}

/** Pages réelles : nécessitent l'API (ignorées sans backend, comme les autres specs de données). */
test.describe('Thèmes — pages (API)', () => {
  let reachable = false;
  let slug: string | null = null;

  test.beforeAll(async ({ request }) => {
    try {
      const res = await request.get(`${API}/library/mots-cles`, { timeout: 3000 });
      reachable = res.ok();
      if (reachable) {
        const list = ((await res.json()) as { data: MotCle[] }).data;
        const theme = list.find((m) => m.parent_slug === 'themes' && (m.documents_count === undefined || m.documents_count > 0));
        slug = theme?.slug ?? null;
      }
    } catch {
      reachable = false;
    }
  });

  test.beforeEach(async ({ page }) => {
    test.skip(!reachable, 'API injoignable : tests de données ignorés');
    await page.setExtraHTTPHeaders({ 'cache-control': 'no-cache' });
  });

  test('/themes : h1, groupes du thésaurus, liens vers les thèmes, axe', async ({ page }) => {
    const res = await page.goto('/themes');
    expect(res?.status()).toBe(200);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Explorer par thème');
    await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', /\/themes$/);
    expect(await page.locator('[data-testid^="theme-group-"]').count()).toBeGreaterThan(0);
    for (const href of await page.getByTestId('theme-index').locator('[data-testid^="theme-link-"]').evaluateAll((els) => els.map((el) => el.getAttribute('href')))) {
      expect(href).toMatch(/^\/themes\/[a-z0-9-]+$/);
    }
    const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    expect(axe.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious')).toEqual([]);
  });

  test('page d\'un thème : fil d\'Ariane, compte, canonique nue, noindex au-delà de la page 1', async ({ page }) => {
    test.skip(slug === null, 'aucun thème');
    const res = await page.goto(`/themes/${slug}`);
    expect(res?.status()).toBe(200);
    const crumbs = page.getByRole('navigation', { name: 'Fil d\'Ariane' });
    await expect(crumbs.getByRole('link', { name: 'Thèmes' })).toHaveAttribute('href', '/themes');
    await expect(page.getByTestId('theme-total')).toBeVisible();
    await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', new RegExp(`/themes/${slug}$`));
    await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /^index/);
    await page.goto(`/themes/${slug}?page=2`);
    await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', new RegExp(`/themes/${slug}$`));
    await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
  });

  test('thème sans document : noindex', async ({ page, request }) => {
    const list = ((await (await request.get(`${API}/library/mots-cles`, { timeout: 3000 })).json()) as { data: MotCle[] }).data;
    const empty = list.find((m) => m.parent_slug !== null && m.documents_count === 0);
    test.skip(!empty, 'aucun thème vide (ou comptes indisponibles)');
    const res = await page.goto(`/themes/${empty!.slug}`);
    expect(res?.status()).toBe(200);
    await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
  });

  test('collection Législation : puce de thème atteinte par Tab, ajoute puis retire mot_cle', async ({ page, request }) => {
    const typed = ((await (await request.get(`${API}/library/mots-cles?type=texte_loi`, { timeout: 3000 })).json()) as { data: MotCle[] }).data;
    const chips = collectionThemes(typed);
    test.skip(chips.length === 0, 'aucun thème compté pour la collection (comptes indisponibles)');
    const first = chips[0]!;
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/collections/texte_loi');
    const zone = page.getByTestId('collection-themes');
    await expect(zone).toBeVisible();
    const chip = zone.getByRole('listitem').getByRole('link').first();
    await expect(chip).toHaveAccessibleName(new RegExp(`^${first.label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')} `));
    // Vraies tabulations depuis le titre de la page (recherche, filtres, tri et « Tous les thèmes » avant les puces).
    await startFrom(page.getByRole('heading', { level: 1 }));
    expect(await tabUntil(page, chip, 40), 'puce atteinte au clavier').toBe(true);

    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(new RegExp(`[?&]mot_cle=${first.slug}(&|$)`));
    const active = page.getByTestId('collection-themes').locator('[aria-current="true"]');
    await expect(active).toHaveCount(1);
    await expect(active).toHaveAccessibleName(new RegExp(`thème appliqué`));

    await active.click();
    await expect(page).not.toHaveURL(/mot_cle=/);
    await expect(page.getByTestId('collection-themes').locator('[aria-current="true"]')).toHaveCount(0);
  });

  test('slug inconnu : 404', async ({ page }) => {
    const res = await page.goto('/themes/theme-inexistant-xyz');
    expect(res?.status()).toBe(404);
  });

  test('aucun défilement horizontal à 320 px', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 640 });
    await page.goto('/themes');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);
  });
});

test('/themes n\'est jamais traité comme une ancienne URL (pas de redirection)', async ({ request }) => {
  for (const path of ['/themes', '/themes/fiscalite']) {
    const res = await request.get(path, { maxRedirects: 0 });
    expect(res.status(), path).not.toBe(301);
    expect(res.headers().location, path).toBeUndefined();
  }
});
