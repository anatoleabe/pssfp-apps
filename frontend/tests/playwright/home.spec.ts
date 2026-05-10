import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Tests de la page d'accueil pssfp.net (PR J).
 *
 * Couvre Hero, 4 chiffres animés, 5 spécialités, 3 actualités featured,
 * partenaires, accès rapides. ISR 5 min.
 */

test.describe('Home — hero (Sprint S5 PR Y : showcase carrousel)', () => {
  test('renders the showcase hero with h1 + 2 CTAs on slide 1', async ({ page }) => {
    await page.goto('/');
    const hero = page.getByRole('heading', { level: 1 });
    await expect(hero).toBeVisible();
    // Sprint S5 PR Y : titre slide 1 = "Former l'élite des finances publiques"
    await expect(hero).toContainText(/finances publiques/i);
    // Nouveaux testids du HomeShowcase
    await expect(page.getByTestId('showcase-cta-primary-identite')).toBeVisible();
    await expect(page.getByTestId('showcase-cta-secondary-identite')).toBeVisible();
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
    await expect(page.getByTestId('spec-card-metiers-fiscalite-comptabilite')).toHaveAttribute(
      'href',
      '/formations/specialites/metiers-fiscalite-comptabilite',
    );
  });

  test('renders 3 featured actualites OR placeholder note (Sprint S5 PR Z)', async ({ page }) => {
    await page.goto('/');
    const news = page.getByTestId('home-actualites');
    await news.scrollIntoViewIfNeeded();
    await expect(news).toBeVisible();
    // Sprint S5 PR Z : fetch réel /v1/articles?featured=true.
    // En CI sans backend, on a le placeholder. Avec backend, 3 cards d'articles.
    const placeholder = news.locator('[data-testid="home-actualites-placeholder"]');
    const articleCards = news.locator('article');
    const hasPlaceholder = (await placeholder.count()) > 0;
    const hasArticles = (await articleCards.count()) > 0;
    expect(hasPlaceholder || hasArticles).toBeTruthy();
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

  test('home has exactly 1 h1 (showcase slide 1 title) — Sprint S5 PR Y', async ({ page }) => {
    await page.goto('/');
    // Sprint S5 PR Y : le HomeShowcase rend 5 slides en DOM mais seul le 1er
    // a un h1 ; les autres ont un h2 pour respecter la hiérarchie SEO.
    const h1Count = await page.locator('h1').count();
    expect(h1Count).toBe(1);
  });
});
