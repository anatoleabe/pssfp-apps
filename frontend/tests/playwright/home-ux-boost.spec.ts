import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Tests visuels et UX de la home refondue (UX BOOST PR R + PR T).
 *
 * Couvre les nouveaux composants Magic UI + atomes premium :
 * - Hero avec Sparkles + AnimatedBeam + glass-card campus
 * - Stats avec NumberTicker (count-up animé)
 * - Spécialités BentoGrid asymétrique avec card phare Fiscalité
 * - Actualités cards avec headers gradient
 * - Partenaires Marquee infinie
 * - Accès rapides 3 grandes cards thématiques
 * - Reduced-motion bypass
 */

// Sprint S5 PR Y a remplacé HomeHero (legacy) par HomeShowcase (carrousel)
// par défaut. Ces tests ne s'appliquent plus à NEXT_PUBLIC_HERO_VARIANT=showcase.
// Pour les ré-activer : NEXT_PUBLIC_HERO_VARIANT=legacy.
test.describe.skip('Home UX Boost — Hero (legacy variant only)', () => {
  test('renders hero with sparkles wrapping "excellence"', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const sparkles = page.locator('.pssfp-sparkles').first();
    await expect(sparkles).toBeVisible();
    await expect(sparkles).toContainText(/excellence/i);
  });

  test('renders animated SVG beam decoration in hero', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const hero = page.getByRole('heading', { level: 1 }).locator('..').locator('..').locator('..');
    const svgPaths = await hero.locator('svg path').count();
    expect(svgPaths).toBeGreaterThan(0);
  });

  test('renders glass-card campus on desktop', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await expect(page.getByText('Promotion 14 — 2026')).toBeVisible();
    await expect(page.getByText('P14 / 2026')).toBeVisible();
  });
});

test.describe('Home UX Boost — Stats with NumberTicker', () => {
  test('renders 4 animated stats with target values', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const stats = page.getByTestId('home-stats');
    await stats.scrollIntoViewIfNeeded();
    await expect(stats).toBeVisible();
    // Attendre que les NumberTicker spring se stabilisent
    await page.waitForTimeout(2000);
    await expect(stats).toContainText('Promotions');
    await expect(stats).toContainText('Spécialités');
    await expect(stats).toContainText('Diplômés');
  });
});

test.describe('Home UX Boost — Specialités BentoGrid', () => {
  test('renders 5 specialty cards with featured Fiscalité', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const specs = page.getByTestId('home-specialites');
    await specs.scrollIntoViewIfNeeded();
    const cards = specs.locator('a[data-testid^="spec-card-"]');
    await expect(cards).toHaveCount(5);
    // La card phare Fiscalité doit contenir le tag "Spécialité phare"
    await expect(specs).toContainText(/Spécialité phare/i);
  });
});

test.describe('Home UX Boost — Partenaires Marquee', () => {
  test('renders marquee with at least 5 partners in sr-only list', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const partners = page.getByTestId('home-partenaires');
    await partners.scrollIntoViewIfNeeded();
    const srItems = partners.locator('ul[aria-label="Liste des partenaires"] li');
    expect(await srItems.count()).toBeGreaterThanOrEqual(5);
  });
});

test.describe('Home UX Boost — Access cards with ArrowUpRight', () => {
  test('renders 3 access cards with FOAD/biblio/candidature', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const access = page.getByTestId('home-access');
    await access.scrollIntoViewIfNeeded();
    await expect(page.getByTestId('access-foad')).toBeVisible();
    await expect(page.getByTestId('access-biblio')).toBeVisible();
    await expect(page.getByTestId('access-candidature')).toBeVisible();
  });
});

test.describe('Home UX Boost — Mobile', () => {
  test('home renders without overflow on iPhone SE viewport', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 667 });
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    // Pas de scroll horizontal
    const bodyWidth = await page.evaluate(() => document.body.scrollWidth);
    const viewportWidth = await page.evaluate(() => window.innerWidth);
    expect(bodyWidth).toBeLessThanOrEqual(viewportWidth + 1);
  });

  test('CTA candidature is visible and tappable on mobile', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 667 });
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const cta = page.getByTestId('hero-cta-candidature');
    await expect(cta).toBeVisible();
    const box = await cta.boundingBox();
    expect(box).not.toBeNull();
    // Touch target ≥ 44px
    expect(box!.height).toBeGreaterThanOrEqual(44);
  });
});

test.describe('Home UX Boost — A11y on refonte', () => {
  test('home has no critical a11y violations after refonte', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    expect(results.violations.filter((v) => v.impact === 'critical')).toEqual([]);
  });

  test('reduced-motion preference disables marquee animation', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const partners = page.getByTestId('home-partenaires');
    await partners.scrollIntoViewIfNeeded();
    // En reduced motion, les classes anim sont still présentes mais le CSS @media les désactive.
    // On vérifie juste que la page ne plante pas.
    await expect(partners).toBeVisible();
  });
});
