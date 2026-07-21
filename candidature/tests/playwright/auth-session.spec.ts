import { expect, test } from '@playwright/test';

test.describe('Session candidat invalide', () => {
  test.beforeEach(async ({ context }) => {
    await context.addCookies([{
      name: 'pssfp_candidat_token',
      value: 'cookie-corrompu',
      domain: 'localhost',
      path: '/',
      httpOnly: true,
      sameSite: 'Lax',
    }]);
  });

  test('affiche le formulaire de connexion et supprime le cookie forgé', async ({ page, context }) => {
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('login-phone-input')).toBeVisible();
    expect((await context.cookies()).find((cookie) => cookie.name === 'pssfp_candidat_token')).toBeUndefined();
  });

  test('affiche l’étape 1 de l’inscription', async ({ page }) => {
    await page.goto('/inscription', { waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('wizard-step-1')).toBeVisible();
  });

  test('redirige une route protégée vers la connexion sans erreur brute', async ({ page }) => {
    await page.goto('/dossier', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/login\?reason=session_expired/);
    await expect(page.getByTestId('login-phone-input')).toBeVisible();
    await expect(page.getByText(/Application error/i)).toHaveCount(0);
  });
});
