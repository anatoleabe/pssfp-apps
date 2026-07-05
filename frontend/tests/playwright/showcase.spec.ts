import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

test.describe('HomeInstitutionalHero', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
  });

  test('presente le positionnement institutionnel et la promotion 14', async ({
    page,
  }) => {
    const hero = page.getByTestId('home-institutional-hero');

    await expect(hero).toBeVisible();
    await expect(hero.getByRole('heading', { level: 1 })).toContainText(
      /finances publiques/i,
    );
    await expect(page.getByTestId('hero-cta-candidature')).toContainText(
      /promotion 14/i,
    );
    await expect(page.getByTestId('hero-cta-formations')).toHaveAttribute(
      'href',
      '/formations',
    );
    await expect(page.locator('[data-testid^="showcase-"]')).toHaveCount(0);
  });

  test('place les acces rapides immediatement apres le hero', async ({
    page,
  }) => {
    const sectionIds = await page.locator('main > section').evaluateAll(
      (sections) =>
        sections.map((section) => section.getAttribute('data-testid')),
    );

    expect(sectionIds.slice(0, 3)).toEqual([
      'home-institutional-hero',
      'home-access',
      'home-stats',
    ]);
  });

  test('ne presente aucune violation critique axe', async ({ page }) => {
    const results = await new AxeBuilder({ page }).analyze();
    const critical = results.violations.filter(
      (violation) => violation.impact === 'critical',
    );

    expect(critical).toEqual([]);
  });
});
