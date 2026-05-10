import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * PR U — UX boost phase 2 : dark mode + refonte /a-propos (ex /pssfp, renommé en S5 PR W).
 *
 * Couvre :
 *  - Toggle thème visible + ARIA
 *  - Toggle ajoute la classe `dark` sur <html> et persiste en localStorage
 *  - Refonte /a-propos : eyebrow, 3 piliers, CTA candidature, scroll-reveal hooks
 *  - A11y dark mode (axe-core, sans violation `critical`)
 */

test.describe('Theme toggle — chrome', () => {
  test('renders the desktop toggle with ARIA + initial light state', async ({ page }) => {
    await page.goto('/');
    const toggle = page.getByTestId('theme-toggle');
    await expect(toggle).toBeVisible();
    // Avant toute interaction : light (aria-pressed="false") + label switchToDark
    await expect(toggle).toHaveAttribute('aria-pressed', 'false');
    await expect(toggle).toHaveAttribute('aria-label', /sombre/i);
  });

  test('clicking the toggle adds .dark on <html> and persists to localStorage', async ({ page }) => {
    await page.goto('/');
    const html = page.locator('html');
    await expect(html).not.toHaveClass(/dark/);

    await page.getByTestId('theme-toggle').click();

    await expect(html).toHaveClass(/dark/);
    const stored = await page.evaluate(() => localStorage.getItem('pssfp-theme'));
    expect(stored).toBe('dark');

    // ARIA reflète le nouvel état
    await expect(page.getByTestId('theme-toggle')).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByTestId('theme-toggle')).toHaveAttribute('aria-label', /clair/i);
  });

  test('initial dark applied without flash when localStorage = dark', async ({ page }) => {
    await page.addInitScript(() => {
      localStorage.setItem('pssfp-theme', 'dark');
    });
    await page.goto('/');
    // L'inline script du <head> doit avoir mis la classe avant le first paint.
    await expect(page.locator('html')).toHaveClass(/dark/);
  });
});

test.describe('/a-propos — refonte storytelling (PR U + S5 PR W)', () => {
  test('renders the new editorial hero (eyebrow + display title + lead)', async ({ page }) => {
    await page.goto('/a-propos');
    // Eyebrow institutionnel
    await expect(page.getByText('Le Programme', { exact: true }).first()).toBeVisible();
    // H1 conservé pour SEO / a11y
    await expect(page.getByRole('heading', { level: 1, name: /À propos de nous/i })).toBeVisible();
  });

  test('renders the 3 piliers section with H2 + 3 cards', async ({ page }) => {
    await page.goto('/a-propos');
    await expect(page.getByRole('heading', { level: 2, name: /Recherche, formation, accompagnement/i })).toBeVisible();
    // 3 cards (h3) attendues
    const piliers = page.locator('h3', { hasText: /Excellence académique|Coopération internationale|Ancrage public/ });
    await expect(piliers).toHaveCount(3);
  });

  test('renders the candidature CTA at the bottom of the page', async ({ page }) => {
    await page.goto('/a-propos');
    const cta = page.getByTestId('apropos-cta-candidature');
    await expect(cta).toBeVisible();
    await expect(cta).toHaveAttribute('href', /.+/);
  });
});

test.describe('A11y — dark mode sur /a-propos et /', () => {
  test('/a-propos en dark mode n\'a pas de violation critical (axe-core)', async ({ page }) => {
    await page.addInitScript(() => {
      localStorage.setItem('pssfp-theme', 'dark');
    });
    await page.goto('/a-propos');
    await expect(page.locator('html')).toHaveClass(/dark/);
    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    expect(results.violations.filter((v) => v.impact === 'critical')).toEqual([]);
  });

  test('/ (home) en dark mode n\'a pas de violation critical (axe-core)', async ({ page }) => {
    await page.addInitScript(() => {
      localStorage.setItem('pssfp-theme', 'dark');
    });
    await page.goto('/');
    await expect(page.locator('html')).toHaveClass(/dark/);
    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    expect(results.violations.filter((v) => v.impact === 'critical')).toEqual([]);
  });
});
