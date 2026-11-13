import { expect, test } from '@playwright/test';

/*
 * Icônes du site (onglet, écran d'accueil du téléphone). Le middleware de
 * langue les réécrivait en /fr/icon.png ou les redirigeait vers /en/icon.png,
 * deux adresses qui n'existent pas : 404 en production (13/11/2026).
 */
test.describe('Icônes du site', () => {
  for (const chemin of ['/icon.png', '/apple-icon.png']) {
    for (const langue of ['fr-FR', 'en-US']) {
      test(`sert ${chemin} sans détour de langue (${langue})`, async ({ request }) => {
        const reponse = await request.get(chemin, { headers: { 'Accept-Language': langue }, maxRedirects: 0 });
        expect(reponse.status()).toBe(200);
        expect(reponse.headers()['content-type']).toContain('image/png');
      });
    }
  }
});
