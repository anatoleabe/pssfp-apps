import { test, expect } from '@playwright/test';

/**
 * Sprint S5.3 — Tests de la refonte design du HomeShowcase.
 *
 * Vérifient les invariants du nouveau rendu épuré : badges-cards à icônes
 * thématiques par slide, hrefs préservés, hauteur cinématique, dots ancrés
 * bas du slide, non-régression reduced-motion + hiérarchie heading.
 *
 * Note : la validation des classes utilitaires (hauteur, position dots) se
 * fait par inspection des classes CSS — robuste vs quirks vh/dvh Playwright.
 */

test.describe('Slidershow S5.3 — pas de top bar / bottom bar institutionnels', () => {
  test('does NOT render a top bar with PSSFP wordmark inside the showcase', async ({ page }) => {
    await page.goto('/');
    const showcase = page.getByTestId('home-showcase');
    await expect(showcase).toBeVisible();
    expect(await showcase.locator('header').count()).toBe(0);
  });

  test('does NOT render a bottom bar institutional footer', async ({ page }) => {
    await page.goto('/');
    const showcase = page.getByTestId('home-showcase');
    await expect(showcase).toBeVisible();
    expect(await showcase.locator('footer').count()).toBe(0);
  });
});

test.describe('Slidershow S5.3 — badges-cards CTAs', () => {
  test('first slide shows two badges with preserved hrefs', async ({ page }) => {
    await page.goto('/');
    const primary = page.getByTestId('showcase-cta-primary-identite');
    const secondary = page.getByTestId('showcase-cta-secondary-identite');

    await expect(primary).toBeVisible();
    await expect(secondary).toBeVisible();

    // Hrefs préservés (régression check vs S5 PR Y).
    await expect(primary).toHaveAttribute('href', '/formations');
    await expect(secondary).toHaveAttribute('href', '#candidature');

    // Labels mis à jour pour le format badge institutionnel.
    await expect(primary).toContainText(/Excellence académique/i);
    await expect(secondary).toContainText(/Promotion 2025/i);
  });

  test('badges have the institutional border-or styling', async ({ page }) => {
    await page.goto('/');
    const primary = page.getByTestId('showcase-cta-primary-identite');
    const className = await primary.getAttribute('class');
    expect(className).toMatch(/border-\[#D4AF6A\]\/40/);
    expect(className).toMatch(/bg-\[#0F3A4A\]\/60/);
    expect(className).toMatch(/backdrop-blur-sm/);
  });

  test('each slide renders an SVG icon inside its primary badge', async ({ page }) => {
    await page.goto('/');
    const slideIds = [
      'identite',
      'excellence-promotions',
      'formation-continue',
      'gouvernance',
      'international',
    ];
    for (const id of slideIds) {
      const primary = page.getByTestId(`showcase-cta-primary-${id}`);
      // Chaque badge doit contenir une icône Lucide (SVG) — confirme que la
      // configuration per-slide `icon` est bien rendue, pas hardcodée.
      const svgCount = await primary.locator('svg').count();
      expect(svgCount).toBeGreaterThanOrEqual(1);
    }
  });
});

test.describe('Slidershow S5.3 — dots ancrés bas slide', () => {
  test('dots container is positioned at bottom-6 with z-20', async ({ page }) => {
    await page.goto('/');
    const dots = page.getByTestId('showcase-dots');
    const className = await dots.getAttribute('class');
    expect(className).toMatch(/bottom-6/);
    expect(className).toMatch(/z-20/);
  });
});

test.describe('Slidershow S5.3 — non-régression scaffolding Embla', () => {
  test('respects prefers-reduced-motion (no autoplay, slide 1 sticky)', async ({ browser }) => {
    const context = await browser.newContext({ reducedMotion: 'reduce' });
    const page = await context.newPage();
    await page.goto('/');
    await expect(page.getByTestId('home-showcase')).toBeVisible();

    await expect(page.getByTestId('showcase-dot-0')).toHaveAttribute('aria-selected', 'true');
    await page.waitForTimeout(2000);
    await expect(page.getByTestId('showcase-dot-0')).toHaveAttribute('aria-selected', 'true');

    await context.close();
  });

  test('hierarchy is preserved: only slide 1 has h1', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByTestId('home-showcase')).toBeVisible();
    const h1Count = await page.locator('h1').count();
    expect(h1Count).toBe(1);
  });
});

test.describe('Slidershow S5.3 — hauteur posée (class-based)', () => {
  test('slide has h-[70vh] min-h-[520px] mobile + md:h-[78vh] md:min-h-[580px] md:max-h-[760px] desktop', async ({
    page,
  }) => {
    await page.goto('/');
    const slide = page.getByTestId('showcase-slide-identite');
    const className = await slide.getAttribute('class');
    expect(className).toMatch(/h-\[70vh\]/);
    expect(className).toMatch(/min-h-\[520px\]/);
    expect(className).toMatch(/md:h-\[78vh\]/);
    expect(className).toMatch(/md:min-h-\[580px\]/);
    expect(className).toMatch(/md:max-h-\[760px\]/);
  });
});
