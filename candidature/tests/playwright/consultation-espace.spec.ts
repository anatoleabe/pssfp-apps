import { expect, test } from '@playwright/test';

/*
 * « Voir son espace » depuis l'administration : le portail échange un code à
 * usage unique contre une session en lecture seule. L'échange et le refus de
 * toute écriture sont couverts côté Laravel (EspaceCandidatTest). Le parcours
 * avec session valide (bandeau, pages de modification renvoyées vers
 * /dossier, message de fin) demande une API réelle et un code créé par
 * l'administration : vérifié à la main avant chaque mise en production.
 * Ici, le comportement du portail face à un lien invalide ou périmé.
 */
test.describe('Consultation de l’espace par un agent', () => {
  test('un lien invalide renvoie à la connexion avec un message clair', async ({ page }) => {
    await page.goto('/auth/consultation?code=invalide', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/login\?reason=consultation_expired/);
    await expect(page.getByText(/Lien de consultation expiré ou déjà utilisé/)).toBeVisible();
    await expect(page.getByTestId('consultation-banner')).toHaveCount(0);
  });

  test('un lien bien formé mais inconnu ne pose aucune session', async ({ page, context }) => {
    await page.goto(`/auth/consultation?code=${'a'.repeat(64)}`, { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/login\?reason=consultation_expired/);
    const names = (await context.cookies()).map((cookie) => cookie.name);
    expect(names).not.toContain('pssfp_candidat_token');
    expect(names).not.toContain('pssfp_candidat_consultation');
  });
});

test.describe('Routes techniques hors langue', () => {
  // Régression : next-intl réécrivait /auth/* en /fr/auth/* (404).
  for (const path of ['/auth/session-expired', '/en/auth/session-expired']) {
    test(`${path} expire la session au lieu d’afficher une 404`, async ({ page }) => {
      await page.goto(path, { waitUntil: 'domcontentloaded' });
      await expect(page).toHaveURL(/\/login\?reason=session_expired/);
    });
  }
});

test.describe('Redirections des routes techniques', () => {
  // Régression : derrière nginx, une Location absolue construite depuis
  // request.url pointait vers l'hôte interne (https://localhost:6003).
  for (const path of ['/auth/session-expired', '/auth/consultation?code=invalide']) {
    test(`${path} redirige avec une adresse relative`, async ({ request }) => {
      const response = await request.get(path, { maxRedirects: 0 });
      expect(response.status()).toBe(307);
      expect(response.headers().location).toMatch(/^\/login\?reason=/);
    });
  }
});
