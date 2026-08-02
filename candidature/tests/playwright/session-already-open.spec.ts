import { test, expect } from '@playwright/test';

/**
 * Audit A-36 : une session déjà ouverte ne doit plus provoquer une redirection
 * muette de /login, /inscription ou /forgot-pin vers /dossier.
 *
 * Ces tests tournent sans backend : le cookie posé n'est donc jamais validé par
 * Sanctum et la session est considérée comme invalide. Ils vérifient le versant
 * qu'on peut établir ici — sans session, les pages d'authentification
 * s'affichent normalement et l'écran d'avertissement reste absent. Le versant
 * « session valide » demande un backend et se vérifie manuellement (cf. README
 * de la recette) ou via auth-session-live.spec.ts.
 */

test.describe('Session déjà ouverte — A-36', () => {
  for (const path of ['/login', '/inscription', '/forgot-pin']) {
    test(`${path} reste accessible et ne redirige pas vers /dossier`, async ({ page }) => {
      const response = await page.goto(path);

      expect(response?.status()).toBe(200);
      await expect(page).toHaveURL(new RegExp(`${path}$`));
      await expect(page.getByTestId('session-already-open')).toHaveCount(0);
    });
  }

  test('un cookie de session forgé est rejeté sans exposer l’écran de session ouverte', async ({
    page,
    context,
  }) => {
    // Jeton syntaxiquement plausible mais jamais émis par Sanctum : le
    // middleware doit le refuser localement, pas afficher « déjà connecté ».
    await context.addCookies([
      {
        name: 'pssfp_candidat_token',
        value: '1|aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        domain: 'localhost',
        path: '/',
      },
    ]);

    await page.goto('/login');

    await expect(page.getByTestId('session-already-open')).toHaveCount(0);
  });
});
