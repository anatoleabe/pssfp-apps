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
 * L'avance du compte de l'auditeur (clé d'API `avance`, ADR-0013 ; `trop_percu` en
 * reste l'alias jusqu'au sous-lot D2) se lit « Avance » dans les deux langues. La
 * carte connectée demande une API réelle et se vérifie au navigateur avant la mise
 * en production : ici, les textes des deux langues et la clé que lit la carte.
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

/*
 * Versements à confirmer par la scolarité sur le reçu bancaire (décision du 15/11/2026) : la carte annonce
 * la part à confirmer et l'état de chaque versement, et tient sans ces clés face à une API antérieure.
 */
test.describe('Versements à confirmer', () => {
  test('les deux langues annoncent la part à confirmer et la vérification de chaque versement', () => {
    const fr = messages('fr').dossier.scolarite;
    const en = messages('en').dossier.scolarite;

    for (const cle of ['aConfirmer', 'aConfirmerConsigne', 'colConfirmation', 'confirmeLe', 'aPresenter']) {
      expect(fr[cle], cle).toBeTruthy();
      expect(en[cle], cle).toBeTruthy();
    }
    expect(fr.aConfirmer).toBe('dont {montant} à confirmer');
  });

  test('la carte lit `a_confirmer` et `confirme_le`, absents d\'une API antérieure', () => {
    const carte = readFileSync(join(__dirname, '..', '..', 'components', 'dossier', 'DossierScolariteCard.tsx'), 'utf8');

    expect(carte).toContain('scolarite.a_confirmer ?? 0');
    expect(carte).toContain('data-testid="scolarite-a-confirmer"');
    expect(carte).toContain('v.confirme_le === null');
  });
});

test.describe('Avance du compte', () => {
  test('la carte lit la clé `avance`, avec repli sur `trop_percu` tant que l\'API n\'est pas à jour', () => {
    const carte = readFileSync(join(__dirname, '..', '..', 'components', 'dossier', 'DossierScolariteCard.tsx'), 'utf8');
    const client = readFileSync(join(__dirname, '..', '..', 'lib', 'api', 'client.ts'), 'utf8');

    expect(client).toMatch(/\n\s+avance: number;/);
    expect(carte).toContain('scolarite.avance ?? scolarite.trop_percu');
    expect(carte).toContain('data-testid="scolarite-avance"');
  });
});
