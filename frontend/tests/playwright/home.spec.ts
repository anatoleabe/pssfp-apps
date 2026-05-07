import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test.describe('Home page', () => {
  test('renders hero heading', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('#hero-heading')).toBeVisible();
    await expect(page.getByRole('navigation', { name: /Navigation principale/ })).toBeVisible();
  });

  test('renders API health status block', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByTestId('api-health-status')).toBeVisible();
  });

  test('has no critical a11y violations', async ({ page }) => {
    await page.goto('/');
    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    const critical = results.violations.filter((v) => v.impact === 'critical');
    expect(critical).toEqual([]);
  });
});
