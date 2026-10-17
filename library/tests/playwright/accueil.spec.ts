import { test, expect, type APIRequestContext } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const API = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/v1';

interface Stats { total: number; by_type: Record<string, number> | []; paid_count: number }

/** Statistiques de l'API, ou null si elle est injoignable (CI : pas de backend). */
async function fetchStats(request: APIRequestContext): Promise<Stats | null> {
  try {
    const res = await request.get(`${API}/library/stats`, { timeout: 3000 });
    if (!res.ok()) return null;
    return ((await res.json()) as { data: Stats }).data;
  } catch {
    return null;
  }
}

test.describe('Accueil — toujours (avec ou sans API)', () => {
  test('un seul h1 et un formulaire de recherche vers /recherche', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('h1')).toHaveCount(1);
    await expect(page.locator('h1')).toContainText('finances publiques');

    const form = page.getByTestId('home-hero').getByRole('search');
    const input = form.getByRole('searchbox', { name: /rechercher dans le catalogue/i });
    await expect(input).toHaveAttribute('name', 'q');
    await expect(input).toHaveAttribute('minlength', '2');
    await input.fill('tva');
    await form.getByRole('button', { name: 'Rechercher' }).click();
    await expect(page).toHaveURL(/\/recherche\?q=tva$/);
  });

  test('suggestions et puces de types mènent au catalogue', async ({ page }) => {
    await page.goto('/');
    const hero = page.getByTestId('home-hero');
    await expect(hero.getByRole('link', { name: 'TVA', exact: true })).toHaveAttribute('href', '/recherche?q=TVA');
    await expect(hero.getByRole('link', { name: 'Mémoires', exact: true })).toHaveAttribute('href', '/collections/memoire');
  });

  test('JSON-LD WebSite + SearchAction', async ({ page }) => {
    await page.goto('/');
    const raw = await page.getByTestId('json-ld').first().textContent();
    const data = JSON.parse(raw ?? '{}') as { '@type': string; potentialAction?: { target?: { urlTemplate?: string } } };
    expect(data['@type']).toBe('WebSite');
    expect(data.potentialAction?.target?.urlTemplate).toContain('/recherche?q={search_term_string}');
  });

  test('axe : aucune violation critique ou grave', async ({ page }) => {
    await page.goto('/');
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    const serious = results.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious');
    expect(serious.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
  });

  test('aucun défilement horizontal à 320 px', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 640 });
    await page.goto('/');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);
  });
});

test.describe('Accueil — données de l\'API', () => {
  let stats: Stats | null = null;

  test.beforeAll(async ({ request }) => {
    stats = await fetchStats(request);
  });

  test.beforeEach(() => {
    test.skip(stats === null, 'API injoignable : tests de données ignorés');
  });

  test('la tuile Mémoires affiche le compteur de l\'API', async ({ page }) => {
    const byType = Array.isArray(stats!.by_type) ? {} : stats!.by_type;
    const n = byType.memoire ?? 0;
    await page.goto('/');
    const tile = page.getByTestId('collection-tile-memoire');
    if (n === 0) {
      await expect(tile).toHaveCount(0);
      return;
    }
    await expect(tile).toHaveAttribute('href', '/collections/memoire');
    await expect(tile.getByTestId('collection-count')).toHaveText(new Intl.NumberFormat('fr-FR').format(n));
  });

  test('six nouveautés au plus', async ({ page }) => {
    await page.goto('/');
    const cards = page.getByTestId('home-latest').locator('article');
    const count = await cards.count();
    expect(count).toBeGreaterThan(0);
    expect(count).toBeLessThanOrEqual(6);
  });

  test('la tuile premium apparaît si et seulement si paid_count > 0', async ({ page }) => {
    await page.goto('/');
    const premium = page.getByTestId('collection-tile-premium');
    if (stats!.paid_count > 0) {
      await expect(premium).toBeVisible();
      await expect(premium).toHaveAttribute('href', '/collections/ouvrage_numerique?acces=payant');
    } else {
      await expect(premium).toHaveCount(0);
    }
  });

  test('« Les plus lus » : liste ordonnée défilante avec commandes', async ({ page }) => {
    await page.goto('/');
    const list = page.getByTestId('most-read-list');
    await expect(list).toHaveJSProperty('tagName', 'OL');
    expect(await list.locator('li').count()).toBeGreaterThanOrEqual(1);
    const next = page.getByRole('button', { name: /suivants/i });
    await expect(next).toHaveAttribute('aria-controls', (await list.getAttribute('id')) ?? '');
    const previous = page.getByRole('button', { name: /précédents/i });
    await expect(previous).toBeDisabled();
    // Défilement d'une carte, dans le conteneur seul (mouvement réduit : saut immédiat).
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.setViewportSize({ width: 375, height: 800 });
    if (await next.isEnabled()) {
      await next.click();
      await expect.poll(() => list.evaluate((el) => el.scrollLeft)).toBeGreaterThan(0);
      await expect(previous).toBeEnabled();
    }
  });

  // Captures d'écran pour la revue visuelle : uniquement si SCREENSHOT_DIR est défini (jamais en CI).
  for (const width of [320, 768, 1440]) {
    test(`@visuel capture ${width} px`, async ({ page }) => {
      test.skip(!process.env.SCREENSHOT_DIR || !!process.env.CI, 'captures manuelles uniquement');
      await page.setViewportSize({ width, height: 900 });
      await page.goto('/');
      await page.waitForLoadState('networkidle');
      await page.screenshot({ path: `${process.env.SCREENSHOT_DIR}/accueil-${width}.png`, fullPage: true });
    });
  }
});
