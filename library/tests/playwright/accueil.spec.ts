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

/**
 * En dev, « Cache-Control: no-cache » contourne le cache de données de Next (comme un rechargement
 * forcé) : la page reflète l'état réel de l'API, même si un passage précédent avec backend a laissé
 * des réponses en cache (ISR 300 s).
 */
const BYPASS_DATA_CACHE = { 'cache-control': 'no-cache' };

test.describe('Accueil — toujours (avec ou sans API)', () => {
  test.beforeEach(async ({ page }) => {
    await page.setExtraHTTPHeaders(BYPASS_DATA_CACHE);
  });

  test('un seul h1 et un formulaire de recherche vers /recherche', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('h1')).toHaveCount(1);

    const form = page.getByRole('main').getByRole('search');
    const input = form.getByRole('searchbox', { name: /rechercher dans le catalogue/i });
    await expect(input).toHaveAttribute('name', 'q');
    await expect(input).toHaveAttribute('minlength', '2');
    // Serveur de dev à froid : la route /recherche se compile (plusieurs secondes) et, sans API, la
    // frontière d'erreur de l'accueil s'hydrate puis re-rend, ce qui peut annuler une soumission
    // lancée trop tôt (l'URL reste « / »). La soumission est donc rejouée jusqu'à aboutir.
    await expect(async () => {
      await input.fill('tva');
      await form.getByRole('button', { name: 'Rechercher' }).click();
      await expect(page).toHaveURL(/\/recherche\?q=tva$/, { timeout: 8_000 });
    }).toPass({ timeout: 45_000 });
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

test.describe('Accueil — API injoignable : frontière d\'erreur', () => {
  let stats: Stats | null = null;

  test.beforeAll(async ({ request }) => {
    stats = await fetchStats(request);
  });

  test.beforeEach(async ({ page }) => {
    test.skip(stats !== null, 'API joignable : la frontière d\'erreur n\'est pas déclenchée');
    await page.setExtraHTTPHeaders(BYPASS_DATA_CACHE);
  });

  test('page d\'erreur utile, dans le gabarit, sans contenu d\'accueil', async ({ page }) => {
    await page.goto('/');
    const error = page.getByTestId('page-error');
    await expect(error).toBeVisible();
    await expect(error.getByRole('alert')).toContainText('momentanément indisponible');
    await expect(page.getByRole('heading', { level: 1 })).toContainText('La bibliothèque est momentanément indisponible');
    // En-tête et pied de page du layout toujours présents.
    await expect(page.getByRole('banner')).toBeVisible();
    await expect(page.getByRole('contentinfo')).toBeVisible();
    // Aucune donnée d'accueil (ni sur-titre chiffré, ni sections).
    await expect(page.getByTestId('home-hero')).toHaveCount(0);
    await expect(page.getByTestId('home-eyebrow')).toHaveCount(0);
    // Sorties : site principal et contact.
    await expect(error.getByRole('link', { name: /site du pssfp/i })).toHaveAttribute('href', /^https?:\/\//);
    await expect(error.getByRole('link', { name: /contacter le pssfp/i })).toHaveAttribute('href', /\/contact$/);
  });

  test('« Réessayer » relance le rendu (toujours indisponible sans API)', async ({ page }) => {
    await page.goto('/');
    const retry = page.getByRole('button', { name: 'Réessayer' });
    await expect(retry).toBeVisible();
    await retry.click();
    await expect(page.getByTestId('page-error')).toBeVisible();
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
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

  test('pas de frontière d\'erreur, titre de la maquette et indicateurs de l\'API', async ({ page }) => {
    await page.setExtraHTTPHeaders(BYPASS_DATA_CACHE);
    await page.goto('/');
    await expect(page.getByTestId('page-error')).toHaveCount(0);
    await expect(page.locator('h1')).toHaveText('Le savoir public. À portée de tous.');
    const statsBar = page.getByTestId('home-stats');
    if (stats!.total === 0) {
      await expect(page.getByTestId('home-stat-documents')).toHaveCount(0);
      return;
    }
    await expect(statsBar.getByTestId('home-stat-documents').getByTestId('home-stat-value')).toHaveText(new Intl.NumberFormat('fr-FR').format(stats!.total));
    const byType = Array.isArray(stats!.by_type) ? {} : stats!.by_type;
    const collections = Object.values(byType).filter((n) => n > 0).length;
    await expect(statsBar.getByTestId('home-stat-collections').getByTestId('home-stat-value')).toHaveText(String(collections));
  });

  test('suggestions de recherche vers /recherche', async ({ page }) => {
    await page.goto('/');
    const hero = page.getByTestId('home-hero');
    await expect(hero.getByRole('search')).toBeVisible();
    await expect(hero.getByRole('link', { name: 'TVA', exact: true })).toHaveAttribute('href', '/recherche?q=TVA');
  });

  test('seules les collections non vides ont une carte, avec le compte de l\'API', async ({ page }) => {
    await page.goto('/');
    const byType = Array.isArray(stats!.by_type) ? {} : stats!.by_type;
    const nonEmpty = Object.entries(byType).filter(([, n]) => n > 0);
    await expect(page.locator('#collections [data-testid^="collection-tile-"]:not([data-testid="collection-tile-premium"])')).toHaveCount(nonEmpty.length);
    for (const [type, n] of nonEmpty) {
      const tile = page.getByTestId(`collection-tile-${type}`);
      await expect(tile).toHaveAttribute('href', `/collections/${type}`);
      await expect(tile.getByTestId('collection-count')).toHaveText(new Intl.NumberFormat('fr-FR').format(n));
    }
  });

  test('législation : section et lien vers la collection si elle compte des textes', async ({ page }) => {
    await page.goto('/');
    const byType = Array.isArray(stats!.by_type) ? {} : stats!.by_type;
    const legal = page.getByTestId('home-legal');
    if ((byType.texte_loi ?? 0) === 0) {
      await expect(legal).toHaveCount(0);
      return;
    }
    await expect(legal.getByTestId('home-legal-cta')).toHaveAttribute('href', '/collections/texte_loi');
    for (const href of await legal.getByRole('listitem').getByRole('link').evaluateAll((els) => els.map((el) => el.getAttribute('href')))) {
      expect(href).toMatch(/^\/collections\/texte_loi\?mot_cle=[a-z0-9-]+$/);
    }
  });

  test('thèmes : liens vers /themes/{slug} et vers /themes', async ({ page }) => {
    await page.goto('/');
    const section = page.getByTestId('home-themes');
    test.skip((await section.count()) === 0, 'thésaurus indisponible ou vide');
    const links = section.getByRole('listitem').getByRole('link');
    expect(await links.count()).toBeGreaterThan(0);
    expect(await links.count()).toBeLessThanOrEqual(8);
    for (const href of await links.evaluateAll((els) => els.map((el) => el.getAttribute('href')))) expect(href).toMatch(/^\/themes\/[a-z0-9-]+$/);
    await expect(section.getByRole('link', { name: /tous les thèmes/i })).toHaveAttribute('href', '/themes');
  });

  test('JSON-LD WebSite + SearchAction', async ({ page }) => {
    await page.goto('/');
    const raw = await page.getByTestId('json-ld').first().textContent();
    const data = JSON.parse(raw ?? '{}') as { '@type': string; potentialAction?: { target?: { urlTemplate?: string } } };
    expect(data['@type']).toBe('WebSite');
    expect(data.potentialAction?.target?.urlTemplate).toContain('/recherche?q={search_term_string}');
  });

  test('huit nouveautés au plus, section cible du lien « Nouveautés »', async ({ page }) => {
    await page.goto('/');
    const latest = page.getByTestId('home-latest');
    await expect(latest).toHaveAttribute('id', 'nouveautes');
    const count = await latest.locator('article').count();
    expect(count).toBeGreaterThan(0);
    expect(count).toBeLessThanOrEqual(8);
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

  test('couvertures dans un lien qui porte déjà le titre : alt vide (pas de doublon vocal)', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/');
    for (const scope of ['home-most-read', 'home-hero']) {
      const alts = await page.getByTestId(scope).locator('a img').evaluateAll((imgs) => imgs.map((img) => img.getAttribute('alt')));
      for (const alt of alts) expect(alt, scope).toBe('');
    }
  });

  test('« Les plus lus » : l\'anneau de focus du premier lien n\'est pas rogné par le carrousel', async ({ page }) => {
    for (const width of [375, 1280]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto('/');
      const list = page.getByTestId('most-read-list');
      await expect(list).toBeVisible();
      const link = list.getByRole('link').first();
      await page.keyboard.press('Tab');
      await link.focus();
      const { gapLeft, gapTop, ring } = await link.evaluate((el) => {
        const box = el.getBoundingClientRect();
        const clip = (el.closest('ol') as HTMLElement).getBoundingClientRect();
        const style = getComputedStyle(el);
        return { gapLeft: box.left - clip.left, gapTop: box.top - clip.top, ring: style.boxShadow };
      });
      expect(ring, `${width} px`).toContain('rgb(74, 46, 103)');
      // ring-2 + ring-offset-4 : 6 px hors du lien, qui doivent rester dans la zone visible de la liste.
      expect(gapLeft, `${width} px`).toBeGreaterThanOrEqual(6);
      expect(gapTop, `${width} px`).toBeGreaterThanOrEqual(6);
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
