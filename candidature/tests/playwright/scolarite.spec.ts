import { expect, test } from '@playwright/test';

/*
 * Scolarité de l'auditeur. Les règles (propriété, mot de passe, solde) sont
 * couvertes côté Laravel (ScolariteApiTest) ; le parcours connecté demande une
 * API réelle et se vérifie au navigateur avant la mise en production. Ici, le
 * comportement du portail sans session.
 */
test.describe('Relevé de situation', () => {
  test('sans session, renvoie à la connexion sans rien servir', async ({ page }) => {
    await page.goto('/dossier/releve', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/login\?reason=session_expired/);
  });
});

test.describe("Lettre d'accès eCampus", () => {
  test('sans session, renvoie à la connexion sans rien servir', async ({ page }) => {
    await page.goto('/dossier/lettre-acces', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/login\?reason=session_expired/);
  });
});
