import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

/*
 * Lien court de la lettre d'accès FOAD reçu par SMS (/l/{jeton}). La page ne
 * télécharge rien à l'ouverture (aperçus de liens des messageries) : la date
 * de naissance est demandée d'abord. L'API (jeton, date, limites) est couverte
 * côté Laravel par LienLettreAccesTest ; ici, l'écran et le relais.
 */
const JETON = 'Ab3dE5fG7hJ9kLmN';

test.describe('Lien de la lettre d’accès', () => {
  test('demande la date de naissance avant tout téléchargement', async ({ page }) => {
    await page.goto(`/l/${JETON}`);

    await expect(page.getByRole('heading', { level: 1 })).toHaveText("Votre lettre d'accès FOAD");
    const date = page.getByLabel('Date de naissance');
    await expect(date).toHaveAttribute('type', 'date');
    await expect(date).toHaveAttribute('required', '');
    await expect(page.getByRole('button', { name: 'Télécharger ma lettre (PDF)' })).toBeVisible();
    await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
  });

  test('signale un lien mal formé sans proposer le formulaire', async ({ page }) => {
    await page.goto('/l/trop-court');

    await expect(page.getByTestId('lien-lettre-invalide')).toBeVisible();
    await expect(page.getByLabel('Date de naissance')).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Se connecter à mon espace' })).toBeVisible();
  });

  test('affiche le nombre d’essais restants après une date erronée', async ({ page }) => {
    await page.goto(`/l/${JETON}?erreur=date&restantes=3`);

    await expect(page.getByTestId('lien-lettre-erreur')).toContainText('3 essais');
  });

  test('revient au formulaire avec un message quand le service est injoignable', async ({ page }) => {
    await page.goto(`/l/${JETON}`);
    await page.getByLabel('Date de naissance').fill('1990-04-12');
    await page.getByRole('button', { name: 'Télécharger ma lettre (PDF)' }).click();

    await expect(page).toHaveURL(new RegExp(`/l/${JETON}\\?erreur=indisponible$`));
    await expect(page.getByTestId('lien-lettre-erreur')).toContainText('momentanément indisponible');
  });

  test('n’a pas de violation d’accessibilité critique', async ({ page }) => {
    await page.goto(`/l/${JETON}?erreur=date&restantes=2`);
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    expect(results.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious')).toEqual([]);
  });
});
