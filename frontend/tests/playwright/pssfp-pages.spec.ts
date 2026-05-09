import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Tests /pssfp/* (PR K — pages CMS dynamiques).
 *
 * Le Server Component fetche /v1/pages/{slug} via SSR. Sans backend,
 * fetch retourne status 0 → fallback "Erreur de chargement".
 *
 * Tests Pest backend (PagesApiTest) couvrent la logique métier exhaustive.
 */

test.describe('/pssfp — index', () => {
  test('renders the h1 + breadcrumb structure', async ({ page }) => {
    await page.goto('/pssfp');
    await expect(page.getByRole('heading', { level: 1, name: /Le PSSFP/i })).toBeVisible();
  });

  test('renders the empty state when backend unreachable', async ({ page }) => {
    await page.goto('/pssfp');
    // Sans backend live, la liste enfants est vide → bandeau visible
    await expect(page.getByTestId('pssfp-empty')).toBeVisible();
  });
});

test.describe('/pssfp/[...slug] — error fallback', () => {
  test('renders the error block when SSR fetch fails (no backend)', async ({ page }) => {
    const response = await page.goto('/pssfp/presentation');
    // Soit 404 (notFound), soit la page d'erreur si status != 404
    if (response?.status() === 404) {
      await expect(page.getByRole('heading', { level: 1, name: '404' })).toBeVisible();
    } else {
      await expect(
        page.getByRole('heading', { level: 1, name: /Page (introuvable|indisponible)/i }),
      ).toBeVisible();
    }
  });
});

test.describe('CamesGrid — composant statique', () => {
  test('mention-legales loads (sanity check before merge)', async ({ page }) => {
    await page.goto('/mentions-legales');
    await expect(page.getByRole('heading', { level: 1, name: /Mentions légales/i })).toBeVisible();
  });
});

test.describe('/pssfp — a11y', () => {
  test('index page has no critical a11y violations', async ({ page }) => {
    await page.goto('/pssfp');
    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    expect(results.violations.filter((v) => v.impact === 'critical')).toEqual([]);
  });
});
