import { expect, test } from '@playwright/test';

/*
 * Rubriques de l'espace auditeur. Le contenu demande une API réelle et se
 * vérifie au navigateur avant la mise en production ; ici, sans session, chaque
 * rubrique renvoie à la connexion sans rien servir.
 */
for (const rubrique of ['parcours', 'scolarite', 'acces', 'documents', 'messages', 'profil']) {
  test(`/dossier/${rubrique} sans session renvoie à la connexion`, async ({ page }) => {
    await page.goto(`/dossier/${rubrique}`, { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/login\?reason=session_expired/);
  });
}
