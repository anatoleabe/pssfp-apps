import { test, expect } from '@playwright/test';

/**
 * NOTE : Les transitions step 1 -> step 2 -> step 3 dépendent de Server
 * Actions Next.js qui font des fetches côté serveur, non interceptables
 * par `page.route()` Playwright. Les tests de transition complète seront
 * couverts par un test E2E avec backend live (PR ultérieure).
 *
 * Ici on teste uniquement le rendu et la validation UI côté client.
 */

test.describe('Forgot PIN — UI client', () => {
  test('renders step 1 by default with phone input', async ({ page }) => {
    await page.goto('/forgot-pin');
    await expect(page.getByTestId('forgot-pin-step-1')).toBeVisible();
    await expect(page.getByTestId('forgot-phone')).toBeVisible();
    await expect(page.getByTestId('forgot-submit-phone')).toBeDisabled();
  });

  test('enables submit when phone is valid E.164', async ({ page }) => {
    await page.goto('/forgot-pin');
    await page.getByTestId('forgot-phone').fill('+237691234567');
    await expect(page.getByTestId('forgot-submit-phone')).toBeEnabled();
  });

  test('rejects non-E.164 phone (submit disabled)', async ({ page }) => {
    await page.goto('/forgot-pin');
    await page.getByTestId('forgot-phone').fill('0691234567');
    await expect(page.getByTestId('forgot-submit-phone')).toBeDisabled();
  });

  test('strips non-digits from phone input', async ({ page }) => {
    await page.goto('/forgot-pin');
    await page.getByTestId('forgot-phone').fill('+237 (691) 234-567');
    await expect(page.getByTestId('forgot-phone')).toHaveValue('+237691234567');
  });
});
