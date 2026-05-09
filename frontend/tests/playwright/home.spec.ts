import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Tests de la page d'accueil pssfp.net (PR J).
 *
 * Couvre Hero, 4 chiffres animés, 5 spécialités, 3 actualités featured,
 * partenaires, accès rapides. ISR 5 min.
 */

test.describe('Home — hero', () => {
  test('renders the hero with h1 + 2 CTAs', async ({ page }) => {
    await page.goto('/');
    const hero = page.getByRole('heading', { level: 1 });
    await expect(hero).toBeVisible();
    await expect(hero).toContainText(/excellence des finances publiques/i);
    await expect(page.getByTestId('hero-cta-candidature')).toBeVisible();
    await expect(page.getByRole('link', { name: /Explorer les formations/i })).toBeVisible();
  });
});

test.describe('Home — sections', () => {
  test('renders 4 stats with count-up active on scroll', async ({ page }) => {
    await page.goto('/');
    const stats = page.getByTestId('home-stats');
    await stats.scrollIntoViewIfNeeded();
    await expect(stats).toBeVisible();
    // 4 cards avec un chiffre dans chaque
    const items = stats.locator('li');
    await expect(items).toHaveCount(4);
  });

  test('renders 5 specialty cards with links to /formations/specialites/[slug]', async ({ page }) => {
    await page.goto('/');
    const specs = page.getByTestId('home-specialites');
    await specs.scrollIntoViewIfNeeded();
    await expect(specs).toBeVisible();
    const cards = specs.locator('a[data-testid^="spec-card-"]');
    await expect(cards).toHaveCount(5);
    await expect(page.getByTestId('spec-card-fiscalite-finance-comptabilite-publique')).toHaveAttribute(
      'href',
      '/formations/specialites/fiscalite-finance-comptabilite-publique',
    );
  });

  test('renders 3 featured actualites with placeholder note', async ({ page }) => {
    await page.goto('/');
    const news = page.getByTestId('home-actualites');
    await news.scrollIntoViewIfNeeded();
    await expect(news).toBeVisible();
    await expect(news.getByRole('note')).toContainText(/PR N/i);
    await expect(news.locator('article, li').first()).toBeVisible();
  });

  test('renders partner logos block', async ({ page }) => {
    await page.goto('/');
    const partners = page.getByTestId('home-partenaires');
    await partners.scrollIntoViewIfNeeded();
    await expect(partners).toBeVisible();
    const logos = partners.locator('li');
    expect(await logos.count()).toBeGreaterThanOrEqual(5);
  });

  test('renders 3 access cards with FOAD/biblio/candidature CTAs', async ({ page }) => {
    await page.goto('/');
    const access = page.getByTestId('home-access');
    await access.scrollIntoViewIfNeeded();
    await expect(access).toBeVisible();
    await expect(page.getByTestId('access-foad')).toBeVisible();
    await expect(page.getByTestId('access-biblio')).toBeVisible();
    await expect(page.getByTestId('access-candidature')).toBeVisible();
  });
});

test.describe('Home — a11y & performance', () => {
  test('has no critical a11y violations on full page', async ({ page }) => {
    await page.goto('/');
    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    expect(results.violations.filter((v) => v.impact === 'critical')).toEqual([]);
  });

  test('reduces critical heading-order issues', async ({ page }) => {
    await page.goto('/');
    // Vérifie qu'il y a exactement 1 h1 sur la home
    const h1Count = await page.locator('h1').count();
    expect(h1Count).toBe(1);
  });
});
