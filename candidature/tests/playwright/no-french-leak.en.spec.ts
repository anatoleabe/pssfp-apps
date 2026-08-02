import { test, expect } from '@playwright/test';

/**
 * Garde-fou anti-résidus français sur la version anglaise.
 *
 * Un premier contrôle maison ne cherchait qu'une liste de mots d'interface et
 * annonçait « 0 résidu » alors que le titre de page affichait « Année
 * académique 2026-2027 », servi depuis la base. Ce test raisonne donc sur des
 * motifs morphologiques du français plutôt que sur une liste de mots, et
 * s'applique au texte réellement visible.
 */

/**
 * Noms propres restant en français au sein d'une phrase anglaise. Tout le reste
 * du contenu français assumé (bandeau ministériel, intitulés officiels des
 * filières, sélecteur de langue) porte `lang="fr"` dans le DOM et est retiré
 * avant analyse — c'est le balisage correct pour un lecteur d'écran, et il
 * fournit ici une allowlist qui ne peut pas se désynchroniser du contenu.
 */
const ALLOWLIST = [
  // Raison sociale de la banque, citée telle quelle dans le communiqué.
  "Crédit Mutuel d'Investissement du Cameroun",
  'Crédit Mutuel d’Investissement du Cameroun',
  // Toponymes et noms d'établissement.
  'Yaoundé',
  'Messa',
  'Soa',
];

/** Mots-outils français : leur présence trahit une phrase non traduite. */
const FRENCH_FUNCTION_WORDS =
  /\b(le|la|les|des|une|aux|du|et|ou|de|pour|dans|avec|sur|par|est|sont|votre|vos|nos|cette|ces|au|vous|nous|qui|que|plus|tout|toute|sans|être|avoir)\b/i;

/** Mots pleins fréquents, y compris hors phrase (titres, libellés). */
const FRENCH_CONTENT_WORDS =
  /\b(année|académique|candidature|candidat|dossier|inscription|scolarité|clôture|pièces|justificatives|téléverser|connexion|déconnecter|promotion|étape|numéro|français|anglais)\b/i;

async function visibleText(page: import('@playwright/test').Page): Promise<string> {
  // Sur un clone détaché, `innerText` se comporte comme `textContent` : il faut
  // donc retirer explicitement scripts et styles, sinon `sans-serif` des piles
  // de polices est compté comme le mot français « sans ».
  const raw = await page.evaluate(() => {
    const clone = document.body.cloneNode(true) as HTMLElement;
    clone.querySelectorAll('script, style, noscript, template').forEach((node) => node.remove());
    // Contenu explicitement balisé comme français : hors analyse.
    clone.querySelectorAll('[lang="fr"]').forEach((node) => node.remove());

    return clone.textContent ?? '';
  });

  return ALLOWLIST.reduce((text, allowed) => text.split(allowed).join(' '), raw).replace(/\s+/g, ' ');
}

const PAGES = ['/en', '/en/login', '/en/inscription', '/en/cgu', '/en/confidentialite', '/en/forgot-pin'];

test.describe('Aucun résidu français sur la version anglaise', () => {
  for (const path of PAGES) {
    test(`${path} ne contient aucun mot français hors noms propres`, async ({ page }) => {
      await page.goto(path);

      const text = await visibleText(page);

      const functionWord = text.match(FRENCH_FUNCTION_WORDS);
      const contentWord = text.match(FRENCH_CONTENT_WORDS);

      expect(
        functionWord?.[0] ?? null,
        `Mot-outil français trouvé sur ${path} : « ${functionWord?.[0]} »`,
      ).toBeNull();
      expect(
        contentWord?.[0] ?? null,
        `Mot français trouvé sur ${path} : « ${contentWord?.[0]} »`,
      ).toBeNull();
    });
  }

  test('le titre de page vient bien de la version anglaise de la campagne', async ({ page }) => {
    await page.goto('/en');

    // Sans backend, le hero retombe sur le message traduit ; avec backend, sur
    // le nom anglais de la campagne. Dans les deux cas, pas de français.
    await expect(page.locator('h1')).not.toContainText('Année');
    await expect(page.locator('h1')).not.toContainText('académique');
  });
});
