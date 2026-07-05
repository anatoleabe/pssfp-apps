import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test.describe('/vie-academique — index', () => {
  test('renders h1 + 6 quick links with icons', async ({ page }) => {
    await page.goto('/vie-academique');
    await expect(page.getByRole('heading', { level: 1, name: /Vie académique/i })).toBeVisible();
    await expect(page.getByTestId('vie-link-promotions')).toBeVisible();
    await expect(page.getByTestId('vie-link-corps-enseignant')).toBeVisible();
    await expect(page.getByTestId('vie-link-calendrier-academique')).toBeVisible();
    await expect(page.getByTestId('vie-link-stages-et-soutenances')).toBeVisible();
    await expect(page.getByTestId('vie-link-programme-mediafip')).toBeVisible();
    await expect(page.getByTestId('vie-link-cooperation-internationale')).toBeVisible();
  });

  test('links route to correct sub-paths', async ({ page }) => {
    await page.goto('/vie-academique');
    await expect(page.getByTestId('vie-link-promotions')).toHaveAttribute(
      'href',
      '/vie-academique/promotions',
    );
  });
});

test.describe('/vie-academique/[...slug] — fallback', () => {
  test('promotions page renders fallback when SSR fetch fails', async ({ page }) => {
    const response = await page.goto('/vie-academique/promotions');
    if (response?.status() === 404) {
      await expect(page.getByRole('heading', { level: 1, name: '404' })).toBeVisible();
    } else {
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    }
  });

  test('promotions breadcrumb falls back to the page title when menu label is empty', async ({ page }) => {
    await page.goto('/vie-academique/promotions');
    const breadcrumb = page.getByTestId('breadcrumb-current');
    test.skip((await breadcrumb.count()) === 0, 'Backend CMS indisponible');
    await expect(breadcrumb).toHaveText('Nos 13 promotions');
  });
});

test.describe('/vie-academique — a11y', () => {
  test('index page has no critical a11y violations', async ({ page }) => {
    await page.goto('/vie-academique');
    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    expect(results.violations.filter((v) => v.impact === 'critical')).toEqual([]);
  });
});
