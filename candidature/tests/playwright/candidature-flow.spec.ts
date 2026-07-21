import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test.describe('Candidature home', () => {
  test('renders the campaign hero', async ({ page }) => {
    await page.goto('/');
    // Le titre par défaut reprend l'année académique lorsqu'aucune API n'est disponible.
    // quand le backend n'est pas joignable depuis le serveur Next.js.
    await expect(page.locator('#hero-heading')).toContainText('Année académique 2026-2027');
  });

  test('has no critical a11y violations on home', async ({ page }) => {
    await page.goto('/');
    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    const critical = results.violations.filter((v) => v.impact === 'critical');
    expect(critical).toEqual([]);
  });
});

test.describe('Candidature login (scaffold)', () => {
  test('renders phone E.164 + 6-digit PIN inputs (ADR-0007)', async ({ page }) => {
    await page.goto('/login');
    const phone = page.getByTestId('login-phone-input');
    const pin = page.getByTestId('login-pin-input');

    await expect(phone).toBeVisible();
    await expect(phone).toHaveAttribute('type', 'tel');
    await expect(phone).toHaveAttribute('autocomplete', 'tel-national');

    await expect(pin).toBeVisible();
    await expect(pin).toHaveAttribute('inputmode', 'numeric');
    await expect(pin).toHaveAttribute('pattern', '^\\d{6}$');
    await expect(pin).toHaveAttribute('autocomplete', 'one-time-code');
  });

  test('PIN input strips non-digits and caps at 6 characters', async ({ page }) => {
    await page.goto('/login');
    const pin = page.getByTestId('login-pin-input');
    await pin.fill('12a3b4c5d67890');
    await expect(pin).toHaveValue('123456');
  });

  test('submit remains available and invalid fields receive inline explanations', async ({ page }) => {
    await page.goto('/login');
    const submit = page.getByRole('button', { name: /se connecter/i });
    await expect(submit).toBeEnabled();
    await page.getByTestId('login-phone-input').fill('691234567');
    await page.getByTestId('login-pin-input').fill('123456');
    await expect(submit).toBeEnabled();
  });
});
