import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Tests du HomeShowcase carrousel (Sprint S5 PR Y).
 *
 * Le hero d'accueil par défaut est désormais le HomeShowcase (5 slides Embla).
 * Le hero legacy reste accessible via NEXT_PUBLIC_HERO_VARIANT=legacy.
 */

test.describe('HomeShowcase — structure', () => {
  test('renders 5 slides with dots and prev/next controls', async ({ page }) => {
    await page.goto('/');
    const showcase = page.getByTestId('home-showcase');
    await expect(showcase).toBeVisible();

    // 5 slides
    const slides = page.locator('[data-testid^="showcase-slide-"]');
    await expect(slides).toHaveCount(5);

    // Dots
    const dots = page.locator('[data-testid^="showcase-dot-"]');
    await expect(dots).toHaveCount(5);

    // Prev / Next
    await expect(page.getByTestId('showcase-prev')).toBeVisible();
    await expect(page.getByTestId('showcase-next')).toBeVisible();
  });

  test('first slide is the institutional identity slide with 2 CTAs', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByTestId('showcase-slide-identite')).toBeVisible();
    await expect(page.getByTestId('showcase-cta-primary-identite')).toHaveAttribute(
      'href',
      '/formations',
    );
    await expect(page.getByTestId('showcase-cta-secondary-identite')).toHaveAttribute(
      'href',
      '#candidature',
    );
  });

  test('clicking next dot advances the carousel', async ({ page }) => {
    await page.goto('/');
    const dot1 = page.getByTestId('showcase-dot-1');
    await dot1.click();
    await expect(dot1).toHaveAttribute('aria-selected', 'true');
  });

  test('keyboard ArrowRight advances the carousel', async ({ page }) => {
    await page.goto('/');
    // Focus the carousel region
    await page.locator('[role="region"][aria-roledescription="carrousel"]').focus();
    await page.keyboard.press('ArrowRight');
    // After ArrowRight, dot 1 should be selected
    await expect(page.getByTestId('showcase-dot-1')).toHaveAttribute('aria-selected', 'true');
  });
});

test.describe('HomeShowcase — accessibility', () => {
  test('home with showcase has no critical a11y violations', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByTestId('home-showcase')).toBeVisible();
    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    expect(results.violations.filter((v) => v.impact === 'critical')).toEqual([]);
  });

  test('respects prefers-reduced-motion (no autoplay)', async ({ browser }) => {
    const context = await browser.newContext({
      reducedMotion: 'reduce',
    });
    const page = await context.newPage();
    await page.goto('/');
    await expect(page.getByTestId('home-showcase')).toBeVisible();
    // Snapshot the first dot — after 7s it should still be selected (no autoplay).
    await expect(page.getByTestId('showcase-dot-0')).toHaveAttribute('aria-selected', 'true');
    await page.waitForTimeout(2000);
    await expect(page.getByTestId('showcase-dot-0')).toHaveAttribute('aria-selected', 'true');
    await context.close();
  });
});
