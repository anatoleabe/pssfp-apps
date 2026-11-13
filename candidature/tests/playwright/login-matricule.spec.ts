import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

/*
 * Connexion des auditeurs avec leurs identifiants FOAD. L'échange avec l'API
 * (comparaison du mot de passe, verrous) est couvert côté Laravel par
 * LoginMatriculeTest ; ici, l'écran : onglets, validation, accessibilité.
 */
test.describe('Connexion par matricule', () => {
  test('l’onglet téléphone reste celui par défaut', async ({ page }) => {
    await page.goto('/login');
    await expect(page.getByTestId('login-tab-telephone')).toHaveAttribute('aria-selected', 'true');
    await expect(page.getByTestId('login-phone-input')).toBeVisible();
    await expect(page.getByTestId('login-matricule-input')).toBeHidden();
  });

  test('bascule sur le matricule au clic, au clavier et par l’adresse', async ({ page }) => {
    await page.goto('/login');
    await page.getByTestId('login-tab-matricule').click();
    await expect(page.getByTestId('login-matricule-input')).toBeVisible();
    await expect(page.getByTestId('login-phone-input')).toBeHidden();

    await page.getByTestId('login-tab-matricule').press('ArrowLeft');
    await expect(page.getByTestId('login-tab-telephone')).toBeFocused();
    await expect(page.getByTestId('login-phone-input')).toBeVisible();

    await page.goto('/login?mode=matricule');
    await expect(page.getByTestId('login-matricule-input')).toBeVisible();
  });

  test('explique un matricule mal formé et un mot de passe manquant', async ({ page }) => {
    await page.goto('/login?mode=matricule');
    await page.getByTestId('login-matricule-input').fill('abc');
    await page.getByTestId('login-matricule-submit').click();
    await expect(page.locator('#matricule-error')).toContainText(/26efp001/);
    await expect(page.locator('#mot-de-passe-error')).toBeVisible();
  });

  test('affiche ou masque le mot de passe', async ({ page }) => {
    await page.goto('/login?mode=matricule');
    const motDePasse = page.getByTestId('login-matricule-password');
    await motDePasse.fill('Ab7@kPq2xZ');
    await expect(motDePasse).toHaveAttribute('type', 'password');
    await page.getByRole('button', { name: 'Afficher le mot de passe' }).click();
    await expect(motDePasse).toHaveAttribute('type', 'text');
  });

  test('empêche le clavier du téléphone de modifier les identifiants', async ({ page }) => {
    // Mot de passe à casse mixte : une majuscule ou une correction automatique
    // le rend faux dès qu'il est affiché en clair.
    await page.goto('/login?mode=matricule');
    await page.getByRole('button', { name: 'Afficher le mot de passe' }).click();
    for (const id of ['login-matricule-input', 'login-matricule-password']) {
      const champ = page.getByTestId(id);
      await expect(champ).toHaveAttribute('autocapitalize', 'none');
      await expect(champ).toHaveAttribute('autocorrect', 'off');
      await expect(champ).toHaveAttribute('spellcheck', 'false');
    }
  });

  test('signale une API injoignable sans erreur brute', async ({ page }) => {
    await page.goto('/login?mode=matricule');
    await page.getByTestId('login-matricule-input').fill('26efp001');
    await page.getByTestId('login-matricule-password').fill('Ab7@kPq2xZ');
    await page.getByTestId('login-matricule-submit').click();
    await expect(page.getByTestId('login-matricule-error')).toContainText(/erreur réseau|incorrect/i);
  });

  test('n’a pas de violation d’accessibilité critique', async ({ page }) => {
    await page.goto('/login?mode=matricule');
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    expect(results.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious')).toEqual([]);
  });
});
