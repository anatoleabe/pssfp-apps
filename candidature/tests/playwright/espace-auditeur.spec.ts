import { expect, test } from '@playwright/test';

/*
 * Rubriques de l'espace auditeur. Le contenu demande une API réelle et se
 * vérifie au navigateur avant la mise en production ; ici, sans session, chaque
 * rubrique renvoie à la connexion sans rien servir.
 */
for (const rubrique of ['parcours', 'resultats', 'scolarite', 'acces', 'documents', 'messages', 'profil']) {
  test(`/dossier/${rubrique} sans session renvoie à la connexion`, async ({ page }) => {
    await page.goto(`/dossier/${rubrique}`, { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/login\?reason=session_expired/);
  });
}

// Vérification publique d'un document : accessible sans session ; sans API joignable,
// elle conclut prudemment qu'aucun document ne correspond.
test('/verifier/{code} est public et n\'affirme jamais l\'authenticité sans réponse de l\'API', async ({ page }) => {
  await page.goto('/verifier/ABCDEF123456', { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveURL(/\/verifier\/ABCDEF123456/);
  await expect(page.getByTestId('verification')).not.toContainText('authentique');
});
