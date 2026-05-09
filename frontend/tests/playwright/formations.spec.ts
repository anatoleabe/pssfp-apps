import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Tests /formations/* (PR L — pages CMS dynamiques formations).
 *
 * Sans backend live, les pages rendent l'h1 + fallback erreur ou 404.
 * Les tests Pest backend (FormationsSeederTest) couvrent la logique métier.
 */

test.describe('/formations — index', () => {
  test('renders the h1 + 6 quick links', async ({ page }) => {
    await page.goto('/formations');
    await expect(page.getByRole('heading', { level: 1, name: /Formations supérieures/i })).toBeVisible();
    await expect(page.getByTestId('formations-link-master')).toBeVisible();
    await expect(page.getByTestId('formations-link-specialites')).toBeVisible();
    await expect(page.getByTestId('formations-link-admission')).toBeVisible();
    await expect(page.getByTestId('formations-link-frais-de-scolarite')).toBeVisible();
  });

  test('quick links target correct routes', async ({ page }) => {
    await page.goto('/formations');
    await expect(page.getByTestId('formations-link-master')).toHaveAttribute('href', '/formations/master');
    await expect(page.getByTestId('formations-link-admission')).toHaveAttribute('href', '/formations/admission');
  });
});

test.describe('/formations/[...slug] — error fallback', () => {
  test('admission page renders fallback when SSR fetch fails', async ({ page }) => {
    const response = await page.goto('/formations/admission');
    if (response?.status() === 404) {
      await expect(page.getByRole('heading', { level: 1, name: '404' })).toBeVisible();
    } else {
      await expect(
        page.getByRole('heading', { level: 1, name: /(introuvable|indisponible|admission)/i }),
      ).toBeVisible();
    }
  });

  test('specialty page renders fallback when SSR fetch fails', async ({ page }) => {
    const response = await page.goto('/formations/specialites/fiscalite-finance-comptabilite-publique');
    if (response?.status() === 404) {
      await expect(page.getByRole('heading', { level: 1, name: '404' })).toBeVisible();
    } else {
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    }
  });
});

test.describe('/formations — a11y', () => {
  test('index page has no critical a11y violations', async ({ page }) => {
    await page.goto('/formations');
    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    expect(results.violations.filter((v) => v.impact === 'critical')).toEqual([]);
  });
});
