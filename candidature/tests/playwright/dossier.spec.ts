import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Tests /dossier sans backend.
 *
 * Le Server Component fait un fetch SSR /v1/applications/me. Sans cookie
 * de token et sans backend, on attend la redirection vers /login. Les tests
 * couvrent ce comportement défensif.
 *
 * Le rendu complet du dossier avec données live est validé en E2E avec
 * backend live (PR ultérieure dédiée regression).
 */

test.describe('Dossier — accès non authentifié', () => {
  test('redirects to /login when no candidat cookie is present', async ({ page }) => {
    const response = await page.goto('/dossier');
    expect(page.url()).toMatch(/\/login/);
    expect(response?.url()).toMatch(/\/login/);
  });

  test('redirects to /login with reason=session_expired query', async ({ page }) => {
    await page.goto('/dossier');
    expect(page.url()).toContain('reason=session_expired');
  });

  test('login page has no critical a11y violations after redirect', async ({ page }) => {
    await page.goto('/dossier');
    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    expect(results.violations.filter((v) => v.impact === 'critical')).toEqual([]);
  });
});

test.describe('Dossier — header adaptatif logged-in', () => {
  test('navigation logged-out shows /login link by default', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('link', { name: /connexion/i })).toBeVisible();
    await expect(page.getByTestId('nav-dossier')).toHaveCount(0);
  });
});
