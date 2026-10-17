import { test, expect, type APIRequestContext } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
// Même base que l'app (playwright.config.ts charge .env.local comme `next dev`).
import { API_BASE_URL as API } from '../../lib/api/client';

interface Stats { total: number; by_type: Record<string, number> | []; paid_count: number; promotions_count?: number }

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
  test('sur-titre du héros : total de l\'API, masqué si elle est injoignable', async ({ page, request }) => {
    const stats = await fetchStats(request);
    // En dev, « Cache-Control: no-cache » contourne le cache de données de Next (comme un
    // rechargement forcé) : sinon des statistiques mises en cache lors d'un passage avec
    // backend resteraient servies (ISR 300 s) et le cas « API injoignable » ne serait pas testé.
    await page.setExtraHTTPHeaders({ 'cache-control': 'no-cache' });
    await page.goto('/');
    const eyebrow = page.getByTestId('home-eyebrow');
    if (stats === null || stats.total === 0) {
      await expect(eyebrow).toHaveCount(0);
    } else {
      await expect(eyebrow).toContainText(`${new Intl.NumberFormat('fr-FR').format(stats.total)} document`);
      await expect(eyebrow).toContainText('finances publiques');
    }
  });

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
    // Promotions réelles : affichées seulement si l'API en compte.
    const promotions = stats!.promotions_count ?? 0;
    if (promotions > 0) await expect(tile).toContainText(`${promotions} promotion`);
    else await expect(tile).not.toContainText('promotion');
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

  test('« Les plus lus » : liste ordonnée étiquetée, avec commandes', async ({ page }) => {
    await page.goto('/');
    const list = page.getByTestId('most-read-list');
    await expect(list).toHaveJSProperty('tagName', 'OL');
    await expect(list).toHaveAttribute('aria-labelledby', 'most-read-title');
    await expect(page.getByRole('list', { name: 'Les plus lus' })).toBeVisible();
    expect(await list.locator('li').count()).toBeGreaterThanOrEqual(1);
    for (const name of [/suivants/i, /précédents/i]) {
      await expect(page.getByRole('button', { name })).toHaveAttribute('aria-controls', 'most-read-list');
    }
  });

  test('« Les plus lus » : défilement d\'une carte, boutons aria-disabled aux bords', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' }); // saut immédiat, pas d'attente d'animation
    await page.setViewportSize({ width: 375, height: 800 });
    await page.goto('/');
    const list = page.getByTestId('most-read-list');
    await expect(list).toBeVisible();
    const overflows = await list.evaluate((el) => el.scrollWidth > el.clientWidth + 1);
    test.skip(!overflows, 'bandeau sans débordement (trop peu de documents) : rien à faire défiler');

    const next = page.getByRole('button', { name: /suivants/i });
    const previous = page.getByRole('button', { name: /précédents/i });
    const scrollLeft = (): Promise<number> => list.evaluate((el) => el.scrollLeft);
    await expect(previous).toHaveAttribute('aria-disabled', 'true');
    await expect(next).toHaveAttribute('aria-disabled', 'false');

    // Au bord, « précédents » reste focalisable (aria-disabled, pas disabled) mais ne fait rien.
    await previous.focus();
    await expect(previous).toBeFocused();
    await page.keyboard.press('Enter');
    await page.waitForTimeout(100); // laisse au défilement l'occasion (indue) de se produire
    expect(await scrollLeft()).toBe(0);

    await next.click();
    await expect.poll(scrollLeft).toBeGreaterThan(0);
    const afterOne = await scrollLeft();
    await expect(previous).toHaveAttribute('aria-disabled', 'false');
    await next.click();
    await expect.poll(scrollLeft).toBeGreaterThan(afterOne);
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
