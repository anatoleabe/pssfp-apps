import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Parcours anglophone : ce projet Playwright annonce `Accept-Language: en-US`
 * (cf. playwright.config.ts), ce qui reproduit exactement la situation d'un
 * visiteur dont le navigateur est en anglais.
 */

test.describe('Détection de langue', () => {
  test('un navigateur anglophone est servi en anglais depuis la racine', async ({ page }) => {
    await page.goto('/');

    await expect(page).toHaveURL(/\/en$/);
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
  });

  test('les URLs françaises restent accessibles malgré un navigateur anglais', async ({ page }) => {
    // Garantie donnée pour intervenir pendant la campagne : aucune adresse
    // déjà diffusée aux candidats ne doit se mettre à rediriger ailleurs.
    await page.goto('/fr/login');
    await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
  });

  test('le contenu de la page d’accueil est bien en anglais', async ({ page }) => {
    await page.goto('/en');

    await expect(page.getByRole('heading', { name: /Composition of the application file/i })).toBeVisible();
    await expect(page.getByText(/Paper copy only/i).first()).toBeVisible();
    await expect(page.getByText(/cover letter addressed to the Chairperson/i)).toBeVisible();
  });

  test('l’en-tête et le pied de page sont traduits', async ({ page }) => {
    await page.goto('/en');

    await expect(page.getByRole('navigation', { name: 'Application navigation' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Terms of use' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Privacy policy' })).toBeVisible();
  });
});

test.describe('Sélecteur de langue', () => {
  test('bascule vers le français et conserve la page courante', async ({ page }) => {
    await page.goto('/en/login');

    await page.getByTestId('language-fr').click();

    await expect(page).toHaveURL(/\/login$/);
    await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
  });

  test('le choix manuel prime sur la langue du navigateur et persiste', async ({ page, context }) => {
    await page.goto('/en');
    await page.getByTestId('language-fr').click();
    await expect(page.locator('html')).toHaveAttribute('lang', 'fr');

    const cookie = (await context.cookies()).find((c) => c.name === 'NEXT_LOCALE');
    expect(cookie?.value).toBe('fr');

    // Nouvelle visite de la racine : le cookie doit l'emporter sur `en-US`.
    await page.goto('/');
    await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
  });

  test('annonce la langue active aux technologies d’assistance', async ({ page }) => {
    await page.goto('/en');

    await expect(page.getByTestId('language-en')).toHaveAttribute('aria-current', 'true');
    await expect(page.getByTestId('language-fr')).not.toHaveAttribute('aria-current', 'true');
  });

  test('reste utilisable au clavier', async ({ page }) => {
    await page.goto('/en');

    const fr = page.getByTestId('language-fr');
    await fr.focus();
    await expect(fr).toBeFocused();
    await page.keyboard.press('Enter');

    await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
  });
});

test.describe('SEO multilingue', () => {
  test('déclare les alternates hreflang et un x-default', async ({ page }) => {
    await page.goto('/en');

    await expect(page.locator('link[rel="alternate"][hreflang="fr"]')).toHaveCount(1);
    await expect(page.locator('link[rel="alternate"][hreflang="en"]')).toHaveCount(1);
    await expect(page.locator('link[rel="alternate"][hreflang="x-default"]')).toHaveCount(1);
  });

  test('une locale inconnue tombe en 404 plutôt qu’en erreur serveur', async ({ page }) => {
    const response = await page.goto('/de/login');
    expect(response?.status()).toBe(404);
  });
});

test.describe('Accessibilité de la version anglaise', () => {
  test('pas de violation critique ou sérieuse sur l’accueil anglais', async ({ page }) => {
    await page.goto('/en');

    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();

    expect(results.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious')).toEqual([]);
  });
});
