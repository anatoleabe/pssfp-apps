import { expect, test } from '@playwright/test';

/*
 * Lettre d'admission signée. L'accès réservé à chaque auditeur est couvert côté
 * Laravel (LettresSigneesTest) ; le parcours connecté demande une API réelle,
 * vérifié au navigateur avant la mise en production. Ici, le comportement du
 * portail sans session.
 */
test.describe('Lettre d’admission signée', () => {
  test('sans session, renvoie à la connexion sans rien servir', async ({ page }) => {
    await page.goto('/dossier/lettre-admission', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/login\?reason=session_expired/);
  });
});

test.describe('Communiqué des résultats', () => {
  test('n’affiche aucun bandeau tant qu’aucun PDF n’est publié', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByTestId('resultats-home-download')).toHaveCount(0);
  });
});
