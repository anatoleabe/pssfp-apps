import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Composition du dossier + communiqué officiel sur la page d'accueil.
 *
 * Comme pour home-campagne.spec.ts, ces tests tournent sans backend : le fetch
 * SSR échoue et la page rend son mode fallback. La liste des pièces est
 * statique (messages/fr.json) donc entièrement vérifiable ici ; le bloc
 * communiqué, lui, dépend de la campagne — on vérifie donc son absence, qui est
 * précisément le comportement attendu tant qu'aucun PDF n'a été déposé.
 */

test.describe('Composition du dossier — termes du communiqué officiel', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/');
  });

  test('reprend les neuf pièces du communiqué', async ({ page }) => {
    const section = page.getByRole('region', { name: /composition du dossier/i });
    await expect(section).toBeVisible();
    await expect(section.getByRole('listitem')).toHaveCount(9);
  });

  test('adresse la lettre de motivation au Président du Comité de Pilotage', async ({ page }) => {
    await expect(
      page.getByText(/lettre de motivation adressée au Président du Comité de Pilotage du PSSFP/i),
    ).toBeVisible();
    // L'ancienne formulation ne doit plus apparaître nulle part.
    await expect(page.getByText(/adressée au Coordonnateur/i)).toHaveCount(0);
  });

  // Les apostrophes sont écrites en droit dans messages/fr.json : les regex
  // acceptent les deux formes pour ne pas casser sur un simple choix de glyphe.
  test('mentionne les pièces absentes de l’ancienne liste', async ({ page }) => {
    await expect(page.getByText(/enveloppe de format A4 timbrée/i)).toBeVisible();
    await expect(page.getByText(/Crédit Mutuel d['’]Investissement du Cameroun/i)).toBeVisible();
    await expect(page.getByText(/copie certifiée conforme du diplôme/i)).toBeVisible();
    await expect(page.getByText(/photocopie légalisée de l['’]acte de naissance/i)).toBeVisible();
  });

  test('signale les pièces exigibles uniquement en version papier', async ({ page }) => {
    await expect(page.getByText('Version papier uniquement')).toHaveCount(2);
  });

  test('rappelle que le dépôt physique conditionne la recevabilité', async ({ page }) => {
    await expect(page.getByText(/dossier papier déposé et réceptionné/i)).toBeVisible();
  });

  test('n’affiche pas de bouton communiqué tant qu’aucun PDF n’est publié', async ({ page }) => {
    await expect(page.getByTestId('communique-download')).toHaveCount(0);
  });

  test('la section dossier ne casse pas l’accessibilité', async ({ page }) => {
    const results = await new AxeBuilder({ page })
      .include('section[aria-labelledby="documents-heading"]')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    expect(results.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious')).toEqual([]);
  });
});
