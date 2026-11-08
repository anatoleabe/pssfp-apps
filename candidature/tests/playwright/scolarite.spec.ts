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

test.describe("Reçu d'un versement", () => {
  test('sans session, renvoie à la connexion sans rien servir', async ({ page }) => {
    await page.goto('/dossier/recus/9f1b2c3d-4e5f-4a6b-8c7d-0e1f2a3b4c5d', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/login\?reason=session_expired/);
  });

  test('un identifiant mal formé ne part pas vers l’API', async ({ page }) => {
    const appels: string[] = [];
    page.on('request', (r) => appels.push(r.url()));
    await page.goto('/dossier/recus/pas-un-uuid', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/login|\/dossier/);
    expect(appels.some((u) => u.includes('/scolarite/recus/'))).toBe(false);
  });
});
