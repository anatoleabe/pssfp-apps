import { expect, test } from '@playwright/test';

test.describe('Hero institutionnel', () => {
  test('oriente directement vers la candidature P14 et les formations', async ({
    page,
  }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const candidature = page.getByTestId('hero-cta-candidature');

    await expect(candidature).toBeVisible();
    await expect(candidature).not.toHaveAttribute('href', '#candidature');
    await expect(page.getByTestId('hero-cta-formations')).toHaveAttribute(
      'href',
      '/formations',
    );
  });

  test('termine la page par un appel a candidature sur le campus', async ({
    page,
  }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const campusCta = page.getByTestId('home-campus-cta');

    await expect(campusCta).toBeVisible();
    await expect(campusCta.locator('img')).toHaveAttribute(
      'src',
      /campus-messa\.webp/,
    );
    await expect(
      campusCta.getByRole('link', { name: /promotion 14/i }),
    ).toBeVisible();
  });

  test('reste sans debordement horizontal sur mobile', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const dimensions = await page.evaluate(() => ({
      clientWidth: document.documentElement.clientWidth,
      scrollWidth: document.documentElement.scrollWidth,
    }));

    expect(dimensions.scrollWidth).toBeLessThanOrEqual(dimensions.clientWidth);
  });
});
