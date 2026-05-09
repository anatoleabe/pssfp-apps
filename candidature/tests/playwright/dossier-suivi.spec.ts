import { test, expect } from '@playwright/test';

test.describe('Dossier suivi — accès non authentifié', () => {
  test('redirects to /login when no candidat cookie', async ({ page }) => {
    await page.goto('/dossier/suivi');
    expect(page.url()).toMatch(/\/login/);
  });
});
