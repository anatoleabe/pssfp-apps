import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Tests fonctionnels page d'accueil sans backend.
 *
 * NOTE : la page accueil fait un fetch SSR vers /v1/applications/campaigns/current.
 * En CI sans backend live, ce fetch échoue côté serveur Next.js et la page tombe
 * en fallback "no campaign". On teste donc ici le mode fallback. Le mode "campagne
 * ouverte avec compte à rebours" sera couvert en E2E avec backend live dans une
 * PR future, ou via mock-server côté Node (out of scope PR E).
 */

test.describe('Home campagne — fallback (sans backend)', () => {
  test('falls back to a "no campaign" notice when the API is unavailable', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('status')).toContainText(/aucune campagne/i);
    await expect(page.getByTestId('cta-inscription')).toHaveCount(0);
  });

  test('home renders the four pillars even when no campagne is open', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByText(/excellence académique/i)).toBeVisible();
    await expect(page.getByText(/foad/i)).toBeVisible();
  });

  test('home has no critical a11y violations', async ({ page }) => {
    await page.goto('/');
    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    expect(results.violations.filter((v) => v.impact === 'critical')).toEqual([]);
  });
});
