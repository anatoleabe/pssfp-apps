import { test, expect, type APIRequestContext, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
// Même base que l'app (playwright.config.ts charge .env.local comme `next dev`).
import { API_BASE_URL as API } from '../../lib/api/client';
import { COLLECTION_PER_PAGE, activeFilterKeys, collectionPageHref, isIndexable, parsePage, withoutFilter } from '../../lib/collections';

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

  test('tri : « Plus consultés » soumis au changement, ordre de l\'API', async ({ page, request }) => {
    const recent = await fetchListing(request, 'type=memoire&tri=recent');
    const populaire = await fetchListing(request, 'type=memoire&tri=populaire');
    test.skip(!recent || !populaire || recent.data.length < 2, 'moins de deux mémoires');
    await page.goto(URL_MEMOIRES);
    expect(await cardSlugs(page)).toEqual(recent!.data.map((d) => d.slug));

    const select = page.getByRole('combobox', { name: 'Trier par' });
    await expect(select).toHaveValue('recent');
    await select.selectOption('populaire');
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

  test('ActiveFilters : une puce par filtre, qui le retire', async ({ page }) => {
    await page.goto(`${URL_MEMOIRES}?acces=libre&langue=fr&tri=titre`);
    const active = page.getByTestId('active-filters');
    await expect(active.getByRole('link', { name: /^Retirer le filtre/ })).toHaveCount(2);
    await active.getByRole('link', { name: 'Retirer le filtre Accès : Libre' }).click();
    await expect(page).not.toHaveURL(/acces=/);
    const url = new URL(page.url());
    expect(url.pathname).toBe(URL_MEMOIRES);
    expect(Object.fromEntries(url.searchParams)).toEqual({ langue: 'fr', tri: 'titre' });
    await expect(page.getByTestId('active-filters').getByRole('link', { name: /^Retirer le filtre/ })).toHaveCount(1);
  });

  test('état vide avec lien de réinitialisation', async ({ page }) => {
    await page.goto(`${URL_MEMOIRES}?annee_min=2100`);
    const empty = page.getByTestId('state-empty');
    await expect(empty).toBeVisible();
    await expect(empty).toHaveAttribute('role', 'status');
    await expect(empty.getByRole('link', { name: 'Retirer les filtres' })).toHaveAttribute('href', URL_MEMOIRES);
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
