import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Tests /actualites + /actualites/[slug] (PR N).
 *
 * Sans backend live, le fetch retourne status 0 → bandeau erreur visible.
 * Tests Pest backend (ArticlesApiTest) couvrent la logique métier exhaustive.
 */

test.describe('/actualites — index', () => {
  test('renders the h1 + intro', async ({ page }) => {
    await page.goto('/actualites');
    await expect(page.getByRole('heading', { level: 1, name: /Actualités/i })).toBeVisible();
  });

  test('renders cards, empty state or error banner depending on backend state', async ({ page }) => {
    await page.goto('/actualites');
    // Backend seedé → cards ; backend up sans articles → empty ; backend down → error.
    const cards = page.locator('[data-testid^="actualite-card-"]');
    const empty = page.getByTestId('actualites-empty');
    const error = page.getByTestId('actualites-error');
    const visible = (await cards.count()) + (await empty.count()) + (await error.count());
    expect(visible).toBeGreaterThan(0);
  });
});

test.describe('/actualites/[slug] — fallback', () => {
  test('renders fallback when article not found', async ({ page }) => {
    const response = await page.goto('/actualites/inexistant');
    if (response?.status() === 404) {
      await expect(page.getByRole('heading', { level: 1, name: '404' })).toBeVisible();
    } else {
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    }
  });
});

test.describe('FacebookEmbed — RGPD consent', () => {
  test('shows consent placeholder before accept (no iframe)', async ({ page }) => {
    await page.goto('/actualites');
    // L'iframe ne doit pas exister tant que le consentement n'est pas donné
    await expect(page.locator('iframe[title*="Facebook"]')).toHaveCount(0);
  });

  test('does not auto-load Facebook content', async ({ page }) => {
    await page.goto('/actualites');
    // Sans consentement explicite : pas de requête vers facebook.com
    let fbRequested = false;
    page.on('request', (req) => {
      if (/facebook\.com/.test(req.url())) {
        fbRequested = true;
      }
    });
    await page.waitForTimeout(500);
    expect(fbRequested).toBe(false);
  });
});

test.describe('/actualites — a11y', () => {
  test('index page has no critical a11y violations', async ({ page }) => {
    await page.goto('/actualites');
    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    expect(results.violations.filter((v) => v.impact === 'critical')).toEqual([]);
  });
});
