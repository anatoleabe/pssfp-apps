import { expect, test } from '@playwright/test';
import { construireSignalement } from '../../lib/erreurs/signalement';

/*
 * Les écrans d'erreur du portail signalent la panne au back-office (page
 * « Erreurs techniques »). Le 13/11/2026, dix connexions en 500 d'un auditeur
 * sur Android n'avaient laissé aucune trace dans l'administration.
 */
test.describe('Signalement des erreurs au back-office', () => {
  test('décrit l’erreur sans paramètres d’adresse et en taille bornée', () => {
    const erreur = Object.assign(new Error('x'.repeat(3000)), { digest: '268796391' });
    const signalement = construireSignalement(erreur, 'https://apply.pssfp.org./login?code=SECRET#haut');

    expect(signalement.page).toBe('https://apply.pssfp.org./login');
    expect(signalement.message).toHaveLength(1000);
    expect(signalement.digest).toBe('268796391');
  });

  test('le relais accepte un signalement et refuse un envoi démesuré ou illisible', async ({ request }) => {
    const accepte = await request.post('/auth/signaler-erreur', {
      data: { page: 'https://apply.pssfp.org/login', message: 'Panne', digest: '1' },
    });
    expect(accepte.status()).toBe(204);

    const demesure = await request.post('/auth/signaler-erreur', { data: { page: '/', message: 'x'.repeat(10_000) } });
    expect(demesure.status()).toBe(413);

    const illisible = await request.post('/auth/signaler-erreur', {
      data: 'pas du json',
      headers: { 'Content-Type': 'application/json' },
    });
    expect(illisible.status()).toBe(400);
  });

  test('l’écran d’erreur signale une connexion refusée par le serveur', async ({ page }) => {
    // Rejoue la panne : la Server Action de connexion répond 500.
    await page.route('**/login*', async (route) => {
      if (route.request().method() === 'POST') {
        await route.fulfill({ status: 500, body: 'Internal Server Error' });
        return;
      }
      await route.continue();
    });
    await page.goto('/login?mode=matricule');
    await page.getByTestId('login-matricule-input').fill('26efp001');
    await page.getByTestId('login-matricule-password').fill('Ab7@kPq2xZ');

    const signalement = page.waitForRequest((r) => r.url().endsWith('/auth/signaler-erreur') && r.method() === 'POST');
    await page.getByTestId('login-matricule-submit').click();

    expect((await signalement).postDataJSON()).toMatchObject({ page: expect.stringMatching(/\/login$/) });
  });
});
