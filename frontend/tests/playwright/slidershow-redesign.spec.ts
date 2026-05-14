import { test, expect } from '@playwright/test';

/**
 * Sprint S5.3 — Tests de la refonte design du HomeShowcase.
 *
 * Vérifient les nouvelles zones visuelles introduites par la maquette
 * institutionnelle : top bar (logo + tagline), badges-cards (Award +
 * GraduationCap), bottom bar institutionnel, et non-régression du contrat
 * existant (hrefs CTA, reduced-motion, hiérarchie heading).
 *
 * Note : la validation de hauteur cinématique et du z-index des dots se fait
 * par inspection des classes CSS plutôt que par boundingBox(), qui est
 * sensible aux quirks vh/dvh sur le mode mobile de Playwright.
 */

test.describe('Slidershow S5.3 — top bar institutionnel', () => {
  test('renders the PSSFP wordmark in the top bar', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByTestId('home-showcase')).toBeVisible();

    const wordmarks = page
      .getByTestId('home-showcase')
      .locator('header')
      .getByText('PSSFP', { exact: true });
    expect(await wordmarks.count()).toBeGreaterThanOrEqual(1);
  });

  test('renders the EXCELLENCE / RIGUEUR / INTÉGRITÉ tagline (desktop)', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/');
    const showcase = page.getByTestId('home-showcase');
    await expect(showcase).toBeVisible();

    await expect(showcase.locator('header').getByText('EXCELLENCE').first()).toBeVisible();
    await expect(showcase.locator('header').getByText('RIGUEUR').first()).toBeVisible();
    await expect(showcase.locator('header').getByText('INTÉGRITÉ').first()).toBeVisible();
  });
});

test.describe('Slidershow S5.3 — bottom bar institutionnel', () => {
  test('renders the programme name and tagline in the bottom bar (desktop)', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/');
    const showcase = page.getByTestId('home-showcase');
    await expect(showcase).toBeVisible();

    await expect(
      showcase.locator('footer').getByText(/Programme Supérieur de Spécialisation/i).first(),
    ).toBeVisible();
    await expect(
      showcase.locator('footer').getByText(/Excellence · Rigueur · Intégrité/i).first(),
    ).toBeVisible();
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
});

test.describe('Slidershow S5.3 — dots z-index above bottom bar', () => {
  test('dots container is positioned at bottom-14 (above the new institutional footer)', async ({
    page,
  }) => {
    await page.goto('/');
    const dots = page.getByTestId('showcase-dots');
    const className = await dots.getAttribute('class');
    // bottom-14 sur mobile, bottom-16 desktop — au-dessus du footer (bottom-0).
    expect(className).toMatch(/bottom-14/);
    // Doit aussi avoir z-20 pour rester au-dessus du footer (z-10).
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

test.describe('Slidershow S5.3 — hauteur cinématique (class-based)', () => {
  test('slide has h-[85vh] min-h-[600px] mobile + md:h-screen md:min-h-[680px] desktop', async ({
    page,
  }) => {
    await page.goto('/');
    const slide = page.getByTestId('showcase-slide-identite');
    const className = await slide.getAttribute('class');
    expect(className).toMatch(/h-\[85vh\]/);
    expect(className).toMatch(/min-h-\[600px\]/);
    expect(className).toMatch(/md:h-screen/);
    expect(className).toMatch(/md:min-h-\[680px\]/);
  });
});
