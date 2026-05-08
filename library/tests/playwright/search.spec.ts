import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test.describe('Library home', () => {
  test('renders title and search bar', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('h1')).toContainText('Bibliothèque');
    await expect(page.getByTestId('library-search-input')).toBeVisible();
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
