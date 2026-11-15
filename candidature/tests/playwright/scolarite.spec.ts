import { readFileSync } from 'node:fs';
import { join } from 'node:path';
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

type Messages = { dossier: { scolarite: Record<string, unknown> } };

function messages(locale: 'fr' | 'en'): Messages {
  return JSON.parse(readFileSync(join(__dirname, '..', '..', 'messages', `${locale}.json`), 'utf8')) as Messages;
}

/*
 * L'excédent des versements (clé d'API `trop_percu`) se lit « Avance » depuis la
 * décision du chef de projet du 15/11/2026. La carte connectée demande une API
 * réelle : ici, les textes des deux langues.
 */
test.describe('Excédent des versements', () => {
  test('les deux langues le présentent comme une avance, jamais comme un trop-perçu', () => {
    const fr = messages('fr').dossier.scolarite;
    const en = messages('en').dossier.scolarite;

    expect(fr.avance).toBe('Avance : {montant}');
    expect(en.avance).toBe('Advance payment: {montant}');
    expect(JSON.stringify(fr)).not.toMatch(/trop[- ]perçu/i);
    expect(JSON.stringify(en)).not.toMatch(/overpa/i);
    expect(fr).not.toHaveProperty('tropPercu');
    expect(en).not.toHaveProperty('tropPercu');
  });
});
