import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { expect, test } from '@playwright/test';
import { formatJourCourt } from '../../lib/format/date';

/*
 * Accès anticipé (Scolarité, lot A1) : sous ses accès, l'auditeur débloqué
 * avant paiement lit « Accès accordé avant paiement : à régulariser avant le
 * JJ/MM/AAAA ». La règle (champ `acces.regulariser_avant`) est couverte côté
 * Laravel par ScolariteApiTest ; la carte connectée demande une API réelle et
 * se vérifie au navigateur avant la mise en production. Ici : les textes des
 * deux langues, le format de la date, et la page fermée sans session.
 */

type Messages = { dossier: { acces: Record<string, unknown> } };

function messages(locale: 'fr' | 'en'): Messages {
  return JSON.parse(readFileSync(join(__dirname, '..', '..', 'messages', `${locale}.json`), 'utf8')) as Messages;
}

test.describe('Accès accordé avant paiement', () => {
  test('les deux langues annoncent la date à laquelle régulariser', () => {
    const fr = messages('fr').dossier.acces;
    const en = messages('en').dossier.acces;

    expect(fr.anticipe).toBe('Accès accordé avant paiement : à régulariser avant le {date}.');
    expect(en.anticipe).toBe('Access granted before payment: to be settled before {date}.');
    // Pas « Votre inscription est réglée » pour un auditeur qui n'a pas encore payé.
    expect(fr.preparationAnticipe).not.toMatch(/réglée/);
    expect(en.preparationAnticipe).not.toMatch(/paid/);
    expect(String(en.preparationAnticipe)).not.toMatch(/\b(vos|votre|les|des|dès)\b/i);
  });

  test('écrit la date au format JJ/MM/AAAA, sans glisser à la veille', () => {
    expect(formatJourCourt('2026-12-01', 'fr')).toBe('01/12/2026');
    expect(formatJourCourt('2026-12-01', 'en')).toBe('01/12/2026');
    expect(formatJourCourt('2027-01-31', 'fr')).toBe('31/01/2027');
    expect(formatJourCourt('pas-une-date', 'fr')).toBe('pas-une-date');
  });

  test('la rubrique des accès reste fermée sans session', async ({ page }) => {
    await page.goto('/dossier/acces', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/\/login\?reason=session_expired/);
    await expect(page.getByTestId('acces-anticipe')).toHaveCount(0);
  });
});
