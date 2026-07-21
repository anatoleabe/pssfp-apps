import { test, expect } from '@playwright/test';

test.describe('Login flow', () => {
  test('renders the login form with phone + PIN inputs', async ({ page }) => {
    await page.goto('/login');
    await expect(page.getByTestId('login-phone-input')).toBeVisible();
    await expect(page.getByTestId('login-pin-input')).toBeVisible();
    await expect(page.getByTestId('login-submit')).toBeEnabled();
  });

  test('enables submit when phone E.164 + 6-digit PIN are filled', async ({ page }) => {
    await page.goto('/login');
    await page.getByTestId('login-phone-input').fill('691234567');
    await page.getByTestId('login-pin-input').fill('472816');
    await expect(page.getByTestId('login-submit')).toBeEnabled();
  });

  test('pre-fills phone from query string ?phone=', async ({ page }) => {
    await page.goto('/login?phone=%2B237691111222');
    await expect(page.getByTestId('login-phone-input')).toHaveValue('691111222');
  });

  test('shows the "session expired" reason banner', async ({ page }) => {
    await page.goto('/login?reason=session_expired');
    await expect(page.getByRole('status')).toContainText(/session a expiré/i);
  });

  test('shows the "PIN reset" reason banner', async ({ page }) => {
    await page.goto('/login?reason=pin_reset');
    await expect(page.getByRole('status')).toContainText(/PIN a été réinitialisé/i);
  });

  test('accepts a Cameroon local number and explains invalid input on blur', async ({ page }) => {
    await page.goto('/login');
    const phone = page.getByTestId('login-phone-input');
    await phone.fill('123');
    await phone.blur();
    await expect(page.locator('#phone-error')).toContainText(/numéro valide/i);

    await phone.fill('691234567');
    await phone.blur();
    await expect(page.getByText(/saisissez un numéro valide/i)).toHaveCount(0);
  });

  // NOTE : le scénario "401 wrong PIN affiche erreur" passe par la Server
  // Action loginAction qui fait un fetch côté serveur — non interceptable
  // par page.route(). Test E2E backend live à venir.
});
