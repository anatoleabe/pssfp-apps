import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Tests du layout global PSSFP (PR I).
 *
 * Couvre Header sticky + Footer 4 colonnes + CookieBanner + pages
 * transversales (mentions-légales, confidentialité, plan-du-site, 404).
 */

test.describe('Layout — Header', () => {
  test('renders sticky header with logo + nav primary on home', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByTestId('site-header')).toBeVisible();
    await expect(page.getByRole('link', { name: /PSSFP — Accueil/ })).toBeVisible();
    await expect(page.getByRole('navigation', { name: /Navigation principale/ })).toBeVisible();
  });

  test('mobile menu toggle opens and closes', async ({ page, browserName }) => {
    test.skip(browserName !== 'chromium');
    await page.setViewportSize({ width: 375, height: 700 });
    await page.goto('/');
    const toggle = page.getByTestId('nav-toggle');
    await expect(toggle).toBeVisible();
    await toggle.click();
    await expect(page.getByTestId('mobile-menu')).toBeVisible();
    // Fermeture par re-clic
    await toggle.click();
    await expect(page.getByTestId('mobile-menu')).toHaveCount(0);
  });

  test('nav primary links navigate (active state on /)', async ({ page }) => {
    await page.goto('/');
    const homeLink = page.getByTestId('nav-home');
    await expect(homeLink).toHaveAttribute('aria-current', 'page');
  });
});

test.describe('Layout — Footer', () => {
  test('renders footer with 4 sections + legal links', async ({ page }) => {
    await page.goto('/');
    const footer = page.getByTestId('site-footer');
    await expect(footer).toBeVisible();
    await expect(footer.getByRole('heading', { name: /À propos/i })).toBeVisible();
    await expect(footer.getByRole('heading', { name: /^Navigation$/i })).toBeVisible();
    await expect(footer.getByRole('heading', { name: /Services numériques/i })).toBeVisible();
    await expect(footer.getByRole('heading', { name: /^Contact$/i })).toBeVisible();
    await expect(footer.getByRole('link', { name: /Mentions légales/i })).toBeVisible();
    await expect(footer.getByRole('link', { name: /Confidentialité/i })).toBeVisible();
    await expect(footer.getByRole('link', { name: /Plan du site/i })).toBeVisible();
  });
});

test.describe('Layout — CookieBanner', () => {
  test('appears on first visit and closes on Refuse', async ({ page, context }) => {
    await context.clearCookies();
    await page.goto('/');
    const banner = page.getByTestId('cookie-banner');
    await expect(banner).toBeVisible();
    await page.getByTestId('cookie-refuse').click();
    await expect(banner).toHaveCount(0);
    // Refresh : la décision est persistée → bannière n'apparaît plus.
    await page.reload();
    await expect(page.getByTestId('cookie-banner')).toHaveCount(0);
  });

  test('can be accepted and persists', async ({ page, context }) => {
    await context.clearCookies();
    await page.addInitScript(() => {
      window.localStorage.removeItem('pssfp_cookie_consent_v1');
    });
    await page.goto('/');
    const banner = page.getByTestId('cookie-banner');
    await expect(banner).toBeVisible();
    await page.getByTestId('cookie-accept').click();
    await expect(banner).toHaveCount(0);
  });
});

test.describe('Pages transversales', () => {
  test('/mentions-legales renders with h1 and 3 sections', async ({ page }) => {
    await page.goto('/mentions-legales');
    await expect(page.getByRole('heading', { level: 1, name: /Mentions légales/i })).toBeVisible();
    await expect(page.getByRole('heading', { name: /Éditeur du site/i })).toBeVisible();
    await expect(page.getByRole('heading', { name: /Hébergement/i })).toBeVisible();
    await expect(page.getByRole('heading', { name: /Propriété intellectuelle/i })).toBeVisible();
  });

  test('/confidentialite renders with h1', async ({ page }) => {
    await page.goto('/confidentialite');
    await expect(page.getByRole('heading', { level: 1, name: /Politique de confidentialité/i })).toBeVisible();
  });

  test('/plan-du-site lists internal + external links', async ({ page }) => {
    await page.goto('/plan-du-site');
    await expect(page.getByRole('heading', { level: 1, name: /Plan du site/i })).toBeVisible();
    await expect(page.getByRole('link', { name: /candidature\.pssfp\.net/ })).toBeVisible();
    await expect(page.getByRole('link', { name: /bibliotheque\.pssfp\.net/ })).toBeVisible();
  });

  test('/something-not-found renders the 404 page', async ({ page }) => {
    const response = await page.goto('/page-qui-nexiste-pas-12345');
    expect(response?.status()).toBe(404);
    await expect(page.getByRole('heading', { level: 1, name: '404' })).toBeVisible();
  });
});

test.describe('Layout — a11y', () => {
  test('home has no critical a11y violations', async ({ page }) => {
    await page.goto('/');
    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    expect(results.violations.filter((v) => v.impact === 'critical')).toEqual([]);
  });

  test('mentions-legales has no critical a11y violations', async ({ page }) => {
    await page.goto('/mentions-legales');
    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    expect(results.violations.filter((v) => v.impact === 'critical')).toEqual([]);
  });
});
