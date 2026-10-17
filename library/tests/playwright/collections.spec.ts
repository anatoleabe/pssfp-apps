import { test, expect, type APIRequestContext, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
// Même base que l'app (playwright.config.ts charge .env.local comme `next dev`).
import { API_BASE_URL as API } from '../../lib/api/client';
import { COLLECTION_PER_PAGE, activeFilterKeys, collectionPageHref, collectionResetHref, isIndexable, parsePage, withoutFilter } from '../../lib/collections';

interface Doc { slug: string; type: string }
interface Listing { data: Doc[]; meta: { total: number; last_page: number } }

/** Liste de l'API (mêmes paramètres que la page), ou null si elle est injoignable. */
async function fetchListing(request: APIRequestContext, qs: string): Promise<Listing | null> {
  try {
    const res = await request.get(`${API}/library/documents?per_page=${COLLECTION_PER_PAGE}&${qs}`, { timeout: 3000 });
    if (!res.ok()) return null;
    return (await res.json()) as Listing;
  } catch {
    return null;
  }
}

/** Contraste WCAG d'une couleur CSS `rgb()`/`rgba()` posée sur du blanc. */
function contrastOnWhite(css: string): number {
  const [r = 0, g = 0, b = 0, a = 1] = (css.match(/[\d.]+/g) ?? []).map(Number);
  const lin = (c: number): number => {
    const v = (c * a + 255 * (1 - a)) / 255;
    return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
  };
  const l = 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
  return 1.05 / (l + 0.05);
}

/** Le focus est-il sur le titre (masqué) des résultats ? Jamais sur body. */
async function focusOnResultsTitle(page: Page): Promise<{ body: boolean; title: boolean }> {
  return page.evaluate(() => ({ body: document.activeElement === null || document.activeElement === document.body, title: document.activeElement?.id === 'results-title' }));
}

/** Contourne le cache de données de Next en dev : la page reflète l'état réel de l'API. */
const BYPASS_DATA_CACHE = { 'cache-control': 'no-cache' };

const URL_MEMOIRES = '/collections/memoire';

/** Slugs des cartes affichées, dans l'ordre. */
async function cardSlugs(page: Page): Promise<string[]> {
  const ids = await page.getByTestId('collection-results').locator('article[data-testid^="document-card-"]').evaluateAll((els) => els.map((el) => el.getAttribute('data-testid') ?? ''));
  return ids.map((id) => id.replace(/^document-card-/, ''));
}

test.describe('Collections — utilitaires', () => {
  test('parsePage : entier ≥ 1, sinon 1', () => {
    expect(parsePage(undefined)).toBe(1);
    expect(parsePage('3')).toBe(3);
    expect(parsePage(['4', '5'])).toBe(4);
    for (const v of ['0', '-2', 'abc', '1.5', '1e3', '', '999999']) expect(parsePage(v)).toBe(1);
  });

  test('activeFilterKeys ignore type et tri', () => {
    expect(activeFilterKeys({ type: 'memoire', tri: 'populaire' })).toEqual([]);
    expect(activeFilterKeys({ type: 'memoire', acces: 'libre', annee_min: 2010, mot_cle: 'tva' })).toEqual(['acces', 'annee_min', 'mot_cle']);
  });

  test('withoutFilter ne modifie pas l\'original', () => {
    const f = { acces: 'libre', langue: 'fr' } as const;
    expect(withoutFilter(f, 'acces')).toEqual({ langue: 'fr' });
    expect(f).toEqual({ acces: 'libre', langue: 'fr' });
  });

  test('collectionPageHref conserve les filtres, sans page 1 ni type', () => {
    expect(collectionPageHref('memoire', { type: 'these', acces: 'libre' }, 1)).toBe('/collections/memoire?acces=libre');
    expect(collectionPageHref('memoire', { acces: 'libre', tri: 'titre' }, 2)).toBe('/collections/memoire?acces=libre&tri=titre&page=2');
  });

  test('collectionResetHref retire tous les filtres mais garde le tri', () => {
    expect(collectionResetHref('memoire', { acces: 'libre', langue: 'fr', tri: 'titre' })).toBe('/collections/memoire?tri=titre');
    expect(collectionResetHref('memoire', { acces: 'libre' })).toBe('/collections/memoire');
  });

  test('isIndexable : faux dès qu\'un filtre, un tri ou une page > 1', () => {
    expect(isIndexable({ type: 'memoire' }, 1)).toBe(true);
    expect(isIndexable({ acces: 'libre' }, 1)).toBe(false);
    expect(isIndexable({ tri: 'titre' }, 1)).toBe(false);
    expect(isIndexable({}, 2)).toBe(false);
  });
});

test.describe('Collections — toujours (avec ou sans API)', () => {
  test.beforeEach(async ({ page }) => {
    await page.setExtraHTTPHeaders(BYPASS_DATA_CACHE);
  });

  test('type inconnu → 404', async ({ page }) => {
    const res = await page.goto('/collections/inconnu');
    expect(res?.status()).toBe(404);
    await expect(page.getByRole('heading', { level: 1 })).toContainText('pas dans nos rayons');
  });

  test('un seul h1, même avec des paramètres invalides', async ({ page }) => {
    await page.goto(`${URL_MEMOIRES}?acces=gratuit&annee_min=abc&langue=xx&page=-2`);
    await expect(page.locator('h1')).toHaveCount(1);
  });

  test('axe : aucune violation critique ou grave', async ({ page }) => {
    await page.goto(URL_MEMOIRES);
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    const serious = results.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious');
    expect(serious.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
  });

  test('aucun défilement horizontal à 320 px', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 640 });
    await page.goto(`${URL_MEMOIRES}?acces=libre&langue=fr`);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);
  });
});

test.describe('Collections — API injoignable : frontière d\'erreur', () => {
  let listing: Listing | null = null;

  test.beforeAll(async ({ request }) => {
    listing = await fetchListing(request, 'type=memoire');
  });

  test('page d\'erreur utile, dans le gabarit', async ({ page }) => {
    test.skip(listing !== null, 'API joignable : la frontière d\'erreur n\'est pas déclenchée');
    await page.setExtraHTTPHeaders(BYPASS_DATA_CACHE);
    await page.goto(URL_MEMOIRES);
    await expect(page.getByTestId('page-error')).toBeVisible();
    await expect(page.getByTestId('page-error').getByRole('alert')).toBeVisible();
    await expect(page.getByTestId('collection-results')).toHaveCount(0);
    await expect(page.getByRole('banner')).toBeVisible();
  });
});

test.describe('Collections — données de l\'API', () => {
  let memoires: Listing | null = null;

  test.beforeAll(async ({ request }) => {
    memoires = await fetchListing(request, 'type=memoire');
  });

  test.beforeEach(async ({ page }) => {
    test.skip(memoires === null, 'API injoignable : tests de données ignorés');
    await page.setExtraHTTPHeaders(BYPASS_DATA_CACHE);
  });

  test('en-tête : fil d\'Ariane, h1, total, recherche limitée au type', async ({ page }) => {
    const res = await page.goto(URL_MEMOIRES);
    expect(res?.status()).toBe(200);
    await expect(page.getByTestId('page-error')).toHaveCount(0);
    const crumbs = page.getByRole('navigation', { name: 'Fil d\'Ariane' });
    await expect(crumbs.getByRole('link', { name: 'Accueil' })).toHaveAttribute('href', '/');
    await expect(crumbs.getByTestId('breadcrumb-current')).toHaveText('Mémoires');
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Mémoires');
    await expect(page.getByTestId('collection-total')).toContainText(new Intl.NumberFormat('fr-FR').format(memoires!.meta.total));
    const search = page.getByTestId('search-form-compact');
    await expect(search.locator('input[type="hidden"][name="type"]')).toHaveValue('memoire');
  });

  test('les cartes affichées sont celles de l\'API, toutes du type demandé', async ({ page }) => {
    await page.goto(URL_MEMOIRES);
    const results = page.getByTestId('collection-results');
    const slugs = await cardSlugs(page);
    expect(slugs).toEqual(memoires!.data.map((d) => d.slug));
    for (const slug of slugs) await expect(results.getByTestId(`document-card-${slug}`)).toContainText('Mémoire');
  });

  test('paramètres invalides ignorés : la page reste servie', async ({ page }) => {
    const res = await page.goto(`${URL_MEMOIRES}?acces=gratuit&annee_min=abc&langue=xx&page=-2&type=these`);
    expect(res?.status()).toBe(200);
    await expect(page.getByTestId('page-error')).toHaveCount(0);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Mémoires');
    expect(await cardSlugs(page)).toEqual(memoires!.data.map((d) => d.slug));
    await expect(page.getByTestId('active-filters')).toHaveCount(0);
  });

  test('tri : choisir une option ne recharge rien, « Trier » applique l\'ordre de l\'API', async ({ page, request }) => {
    const recent = await fetchListing(request, 'type=memoire&tri=recent');
    const populaire = await fetchListing(request, 'type=memoire&tri=populaire');
    test.skip(!recent || !populaire || recent.data.length < 2, 'moins de deux mémoires');
    await page.goto(URL_MEMOIRES);
    expect(await cardSlugs(page)).toEqual(recent!.data.map((d) => d.slug));

    const select = page.getByRole('combobox', { name: 'Trier par' });
    await expect(select).toHaveValue('recent');
    await select.selectOption('populaire');
    // WCAG 3.2.2 : aucun changement de contexte au simple choix d'une option.
    await page.waitForTimeout(300);
    await expect(page).toHaveURL(new RegExp(`${URL_MEMOIRES}$`));
    const trier = page.getByRole('button', { name: 'Trier' });
    await expect(trier).toBeVisible();
    await trier.click();
    await expect(page).toHaveURL(/[?&]tri=populaire/);
    await expect.poll(() => cardSlugs(page)).toEqual(populaire!.data.map((d) => d.slug));
    await expect(select).toHaveValue('populaire');
    if (recent!.data.map((d) => d.slug).join() !== populaire!.data.map((d) => d.slug).join()) {
      expect(await cardSlugs(page)).not.toEqual(recent!.data.map((d) => d.slug));
    }
  });

  test('la pagination conserve les filtres', async ({ page, request }) => {
    const libres = await fetchListing(request, 'type=memoire&acces=libre');
    test.skip(!libres || libres.meta.last_page < 2, 'une seule page de mémoires en accès libre');
    await page.goto(`${URL_MEMOIRES}?acces=libre`);
    const pagination = page.getByRole('navigation', { name: 'Pagination' });
    await expect(pagination.getByRole('link', { name: 'Page 2', exact: true })).toHaveAttribute('href', '/collections/memoire?acces=libre&page=2');
    await pagination.getByRole('link', { name: 'Page 2', exact: true }).click();
    await expect(page).toHaveURL(/\/collections\/memoire\?acces=libre&page=2$/);
    await expect(pagination.getByRole('link', { name: /précédente/i })).toHaveAttribute('href', '/collections/memoire?acces=libre');
  });

  test('ActiveFilters : une puce par filtre, qui le retire ; le total est annoncé', async ({ page, request }) => {
    const libres = await fetchListing(request, 'type=memoire&acces=libre&langue=fr');
    await page.goto(`${URL_MEMOIRES}?acces=libre&langue=fr&tri=titre`);
    const count = page.getByTestId('results-count');
    await expect(count).toHaveAttribute('role', 'status');
    await expect(page.getByRole('main').locator('[role="status"]')).toHaveCount(1);
    await expect(count).toContainText(String(libres!.meta.total));
    const active = page.getByTestId('active-filters');
    await expect(active.getByRole('link', { name: /^Retirer le filtre/ })).toHaveCount(2);
    await active.getByRole('link', { name: 'Retirer le filtre Accès : Libre' }).click();
    await expect(page).not.toHaveURL(/acces=/);
    const url = new URL(page.url());
    expect(url.pathname).toBe(URL_MEMOIRES);
    expect(Object.fromEntries(url.searchParams)).toEqual({ langue: 'fr', tri: 'titre' });
    await expect(page.getByTestId('active-filters').getByRole('link', { name: /^Retirer le filtre/ })).toHaveCount(1);
    // Même région vivante, nouveau total (navigation client).
    await expect(count).toContainText(new Intl.NumberFormat('fr-FR').format(memoires!.meta.total));
    await expect(page.getByRole('main').locator('[role="status"]')).toHaveCount(1);
  });

  test('spécialité : puce avec l\'intitulé connu, aucune puce pour un slug inconnu', async ({ page }) => {
    await page.goto(`${URL_MEMOIRES}?specialite=marches-publics-partenariats-public-prive`);
    await expect(page.getByTestId('active-filters').getByRole('link', { name: 'Retirer le filtre Spécialité : Marchés Publics et Partenariats Public-Privés' })).toBeVisible();
    await page.goto(`${URL_MEMOIRES}?specialite=inconnue`);
    await expect(page.getByTestId('active-filters')).toHaveCount(0);
    await expect(page.getByText('inconnue')).toHaveCount(0);
  });

  test('mot-clé sans intitulé connu : aucune puce, jamais le slug brut', async ({ page }) => {
    await page.goto(`${URL_MEMOIRES}?mot_cle=mot-cle-inconnu-xyz`);
    await expect(page.getByTestId('active-filters')).toHaveCount(0);
    await expect(page.getByText('mot-cle-inconnu-xyz')).toHaveCount(0);
  });

  test('pastille d\'accès cochée : coche visible et bordure épaissie en couleurs forcées', async ({ page }) => {
    await page.goto(`${URL_MEMOIRES}?acces=libre`);
    const form = page.getByRole('form', { name: 'Affiner' });
    const pills = form.getByTestId('access-pill');
    await expect(pills.filter({ hasText: 'Libre' }).getByTestId('access-pill-check')).toBeVisible();
    await expect(pills.filter({ hasText: 'Tous' }).getByTestId('access-pill-check')).toBeHidden();
    await page.emulateMedia({ forcedColors: 'active' });
    const width = (name: string): Promise<string> => pills.filter({ hasText: name }).evaluate((el) => getComputedStyle(el).borderTopWidth);
    expect(await width('Libre')).toBe('3px');
    expect(await width('Tous')).toBe('1px');
  });

  test('état vide : total annoncé, une seule région vivante, réinitialisation qui garde le tri', async ({ page }) => {
    await page.goto(`${URL_MEMOIRES}?annee_min=2100&tri=titre`);
    const empty = page.getByTestId('state-empty');
    await expect(empty).toBeVisible();
    await expect(page.getByTestId('results-count')).toHaveText('Aucun document');
    await expect(page.getByRole('main').locator('[role="status"]')).toHaveCount(1);
    await expect(empty.getByRole('link', { name: 'Retirer les filtres' })).toHaveAttribute('href', `${URL_MEMOIRES}?tri=titre`);
    await expect(page.getByRole('form', { name: 'Affiner' }).getByRole('link', { name: 'Réinitialiser' })).toHaveAttribute('href', `${URL_MEMOIRES}?tri=titre`);
  });

  test('champs de filtre et de tri : bordure au repos ≥ 3:1, anneau de focus prune opaque', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(URL_MEMOIRES);
    const form = page.getByTestId('filter-form-desktop');
    const fields = [form.getByLabel('De', { exact: true }), form.getByRole('combobox', { name: 'Langue' }), page.getByRole('combobox', { name: 'Trier par' })];
    for (const field of fields) {
      await expect(field).toBeVisible();
      const border = await field.evaluate((el) => getComputedStyle(el).borderTopColor);
      expect(contrastOnWhite(border), border).toBeGreaterThanOrEqual(3);
    }
    await page.keyboard.press('Tab');
    const sort = page.getByRole('combobox', { name: 'Trier par' });
    await sort.focus();
    expect(await sort.evaluate((el) => getComputedStyle(el).boxShadow)).toContain('rgb(74, 46, 103)');
  });

  test('puce retirée au clavier : focus sur le titre des résultats, jamais perdu sur body', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`${URL_MEMOIRES}?acces=libre&langue=fr`);
    const chip = page.getByTestId('active-filters').getByRole('link', { name: 'Retirer le filtre Accès : Libre' });
    await chip.focus();
    await page.keyboard.press('Enter');
    await expect(page).not.toHaveURL(/acces=/);
    await expect(page.locator('#results-title')).toBeFocused();

    // « Retirer les filtres » (lien qui disparaît avec le dernier filtre) : même repli.
    await page.goto(`${URL_MEMOIRES}?acces=libre&langue=fr`);
    await page.getByTestId('active-filters').getByRole('link', { name: 'Retirer les filtres' }).focus();
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(new RegExp(`${URL_MEMOIRES}$`));
    await expect(page.locator('#results-title')).toBeFocused();
  });

  test('état vide : la réinitialisation au clavier rend le focus au titre des résultats', async ({ page }) => {
    await page.goto(`${URL_MEMOIRES}?annee_min=2100`);
    await page.getByTestId('state-empty').getByRole('link', { name: 'Retirer les filtres' }).focus();
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(new RegExp(`${URL_MEMOIRES}$`));
    await expect(page.getByTestId('state-empty')).toHaveCount(0);
    expect(await focusOnResultsTitle(page)).toEqual({ body: false, title: true });
  });

  test('pagination au clavier : pas de saut en haut de page, focus sur le titre des résultats', async ({ page }) => {
    test.skip(memoires!.meta.last_page < 2, 'une seule page de mémoires');
    await page.setViewportSize({ width: 1280, height: 700 });
    await page.goto(URL_MEMOIRES);
    const pagination = page.getByRole('navigation', { name: 'Pagination' });
    await pagination.getByRole('link', { name: 'Page 2', exact: true }).focus();
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/page=2$/);
    await expect(page.locator('#results-title')).toBeFocused();
    // Le titre des résultats est amené dans la fenêtre, sous l'en-tête collant (pas de retour tout en haut).
    const { top, header, scrollY } = await page.evaluate(() => ({
      top: document.getElementById('results-title')?.getBoundingClientRect().top ?? -1,
      header: document.querySelector('[data-testid="site-header"]')?.getBoundingClientRect().height ?? 0,
      scrollY: window.scrollY,
    }));
    expect(scrollY).toBeGreaterThan(0);
    expect(top).toBeGreaterThanOrEqual(header - 1);
    expect(top).toBeLessThan(700);
  });

  test('métadonnées : canonique sans filtres, noindex dès qu\'un filtre', async ({ page }) => {
    await page.goto(URL_MEMOIRES);
    await expect(page).toHaveTitle(/^Mémoires/);
    await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', /\/collections\/memoire$/);
    await expect(page.locator('meta[name="robots"]')).not.toHaveAttribute('content', /noindex/);

    await page.goto(`${URL_MEMOIRES}?acces=libre&page=2`);
    await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', /\/collections\/memoire$/);
    await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
  });

  test('axe avec filtres actifs, panneau mobile ouvert', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 800 });
    await page.goto(`${URL_MEMOIRES}?acces=libre&langue=fr`);
    await page.getByTestId('filters-mobile').locator('summary').click();
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    const serious = results.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious');
    expect(serious.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
  });

  // Captures d'écran pour la revue visuelle : uniquement si SCREENSHOT_DIR est défini (jamais en CI).
  for (const width of [320, 1440]) {
    test(`@visuel capture ${width} px`, async ({ page }) => {
      test.skip(!process.env.SCREENSHOT_DIR || !!process.env.CI, 'captures manuelles uniquement');
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`${URL_MEMOIRES}?acces=libre`);
      await page.waitForLoadState('networkidle');
      await page.screenshot({ path: `${process.env.SCREENSHOT_DIR}/collections-memoire-${width}.png`, fullPage: true });
    });
  }
});

test.describe('Collections — sans JavaScript', () => {
  test.use({ javaScriptEnabled: false });
  let memoires: Listing | null = null;

  test.beforeAll(async ({ request }) => {
    memoires = await fetchListing(request, 'type=memoire');
  });

  test.beforeEach(async ({ page }) => {
    test.skip(memoires === null, 'API injoignable : formulaire non rendu');
    await page.setExtraHTTPHeaders(BYPASS_DATA_CACHE);
  });

  test('le formulaire de filtres est soumis en GET', async ({ page }) => {
    await page.goto(URL_MEMOIRES);
    const form = page.getByRole('form', { name: 'Affiner' });
    await form.getByRole('radio', { name: 'Libre' }).check();
    await form.getByRole('button', { name: 'Appliquer' }).click();
    await expect(page).toHaveURL(/\/collections\/memoire\?.*acces=libre/);
    await expect(page.getByRole('form', { name: 'Affiner' }).getByRole('radio', { name: 'Libre' })).toBeChecked();
    await expect(page.getByTestId('active-filters').getByRole('link', { name: 'Retirer le filtre Accès : Libre' })).toBeVisible();
    await expect(page.getByRole('form', { name: 'Affiner' }).getByRole('link', { name: 'Réinitialiser' })).toHaveAttribute('href', URL_MEMOIRES);
  });

  test('le tri fonctionne avec son bouton « Trier »', async ({ page }) => {
    await page.goto(URL_MEMOIRES);
    await page.getByRole('combobox', { name: 'Trier par' }).selectOption('titre');
    await page.getByRole('button', { name: 'Trier' }).click();
    await expect(page).toHaveURL(/[?&]tri=titre/);
  });

  test('mobile : le panneau « Filtrer » s\'ouvre nativement', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 800 });
    await page.goto(URL_MEMOIRES);
    const details = page.getByTestId('filters-mobile');
    const apply = details.getByRole('button', { name: 'Appliquer' });
    await expect(apply).toBeHidden();
    await details.locator('summary').click();
    await expect(apply).toBeVisible();
  });
});
