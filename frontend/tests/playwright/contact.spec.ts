import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Tests /contact (PR O).
 *
 * Tests Pest backend (ContactApiTest) couvrent la logique métier
 * (validation, rate-limit, Mailables).
 */

test.describe('/contact — page', () => {
  test('renders the h1 + form fields + coordonnees', async ({ page }) => {
    await page.goto('/contact');
    // Vise l'id stable `contact-heading` (h1) — robuste au wording éditorial
    // (refonte page contact : H1 = "Nous sommes à votre écoute").
    await expect(page.locator('h1#contact-heading')).toBeVisible();
    await expect(page.getByTestId('contact-form')).toBeVisible();
    await expect(page.getByTestId('contact-nom')).toBeVisible();
    await expect(page.getByTestId('contact-email')).toBeVisible();
    await expect(page.getByTestId('contact-message')).toBeVisible();
    await expect(page.getByTestId('contact-cgu')).toBeVisible();
    await expect(page.getByTestId('contact-submit')).toBeVisible();
  });

  test('shows the Google Maps consent placeholder before user accept', async ({ page }) => {
    await page.goto('/contact');
    await expect(page.getByTestId('map-consent')).toBeVisible();
    // Pas d'iframe Google avant consentement
    await expect(page.locator('iframe[title*="Google Maps"]')).toHaveCount(0);
  });

  test('does not request google.com before consent', async ({ page }) => {
    let googleHit = false;
    page.on('request', (req) => {
      if (/google\.com/.test(req.url())) googleHit = true;
    });
    await page.goto('/contact');
    await page.waitForTimeout(500);
    expect(googleHit).toBe(false);
  });
});

test.describe('FoadSticky — global', () => {
  test('is visible on /contact (md+ viewport)', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 720 });
    await page.goto('/contact');
    await expect(page.getByTestId('foad-sticky')).toBeVisible();
    await expect(page.getByTestId('foad-sticky')).toHaveAttribute('target', '_blank');
  });

  test('is hidden on small viewport', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 700 });
    await page.goto('/contact');
    await expect(page.getByTestId('foad-sticky')).not.toBeVisible();
  });
});

test.describe('/contact — a11y', () => {
  test('page has no critical a11y violations', async ({ page }) => {
    await page.goto('/contact');
    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    expect(results.violations.filter((v) => v.impact === 'critical')).toEqual([]);
  });
});
