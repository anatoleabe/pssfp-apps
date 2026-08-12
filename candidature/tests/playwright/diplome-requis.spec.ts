import { test, expect, Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const PAYS_FIXTURE = [
  { code_iso: 'CM', nom: 'Cameroun', indicatif: '+237' },
  { code_iso: 'FR', nom: 'France', indicatif: '+33' },
];

const SPECIALITES_FIXTURE = [
  { slug: 'fiscalite-finance-comptabilite-publique', label: 'Fiscalité - Finance - Comptabilité Publique' },
];

const REGIONS_FIXTURE = [
  { code: 'CENTRE', nom: 'Centre', quota_admission: 0.15, chef_lieu: 'Yaoundé', order: 2 },
];

const DEPARTEMENTS_CENTRE = [
  { code: 'Mfoundi', nom: 'Mfoundi', chef_lieu: 'Yaoundé', region_code: 'CENTRE' },
];

async function setupReferenceMocks(page: Page): Promise<void> {
  await page.route('**/v1/reference/pays', (r) =>
    r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: PAYS_FIXTURE }) }),
  );
  await page.route('**/v1/reference/specialites', (r) =>
    r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: SPECIALITES_FIXTURE }) }),
  );
  await page.route('**/v1/reference/regions-cameroun', (r) =>
    r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: REGIONS_FIXTURE }) }),
  );
  await page.route('**/v1/reference/departements-cameroun**', (r) =>
    r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: DEPARTEMENTS_CENTRE }) }),
  );
}

async function gotoStep3(page: Page): Promise<void> {
  await setupReferenceMocks(page);
  await page.goto('/inscription');

  await page.getByTestId('step1-specialite').click();
  await page.getByRole('option', { name: /Fiscalité/i }).click();
  await page.getByTestId('step1-prenom').fill('Jean');
  await page.getByTestId('step1-nom').fill('Dupont');
  await page.getByTestId('step1-date-naissance').fill('1990-06-15');
  await page.getByTestId('step1-lieu-naissance').fill('Yaoundé');
  await page.getByTestId('wizard-next').click();

  await page.getByTestId('step2-adresse').fill('BP 1234 Yaoundé');
  await page.getByLabel('Ville de résidence').fill('Yaoundé');
  await page.getByTestId('step2-phone-number').fill('691234567');
  await page.getByTestId('region-select').click();
  await page.getByRole('option', { name: 'Centre' }).click();
  await page.getByTestId('departement-select').click();
  await page.getByRole('option', { name: 'Mfoundi' }).click();
  await page.getByLabel('Adresse e-mail personnelle').fill('jean.dupont@example.com');
  await page.getByTestId('wizard-next').click();

  await expect(page.getByTestId('wizard-step-3')).toBeVisible();
}

/** Remplit le bloc « diplôme le plus élevé » et le bloc « diplôme requis ». */
async function fillDiplomes(page: Page, domaine = 'droit'): Promise<void> {
  await page.getByTestId('step3-diplome-obtenu').selectOption({ label: 'Licence' });
  await page.getByTestId('step3-annee-diplome').fill('2015');
  await page.getByTestId('step3-specialite-diplome').fill('Économie publique');
  await page.getByTestId('step3-institut').click();
  await page.getByRole('option', { name: /Université de Yaoundé II/i }).click();

  await page.getByTestId('step3-diplome-requis').selectOption('licence-bachelor');
  await page.getByTestId('step3-annee-diplome-requis').fill('2012');
  await page.getByTestId('step3-domaine-diplome-requis').selectOption(domaine);
  await page.getByTestId('step3-institut-diplome-requis').click();
  await page.getByRole('option', { name: /Université de Yaoundé II/i }).click();
}

test.describe('Étape 3 — diplôme requis', () => {
  test('la spécialité du diplôme requis n’apparaît que pour le domaine Autres', async ({ page }) => {
    await gotoStep3(page);

    await page.getByTestId('step3-domaine-diplome-requis').selectOption('droit');
    await expect(page.getByTestId('step3-specialite-diplome-requis')).toHaveCount(0);

    await page.getByTestId('step3-domaine-diplome-requis').selectOption('autres');
    await expect(page.getByTestId('step3-specialite-diplome-requis')).toBeVisible();

    // Quitter « Autres » doit effacer la valeur, sinon une spécialité masquée
    // resterait enregistrée et s'imprimerait sur le récépissé.
    await page.getByTestId('step3-specialite-diplome-requis').fill('Sciences politiques');
    await page.getByTestId('step3-domaine-diplome-requis').selectOption('gestion');
    await page.getByTestId('step3-domaine-diplome-requis').selectOption('autres');

    await expect(page.getByTestId('step3-specialite-diplome-requis')).toHaveValue('');
  });

  test('le bloc diplôme requis est obligatoire pour passer à l’étape suivante', async ({ page }) => {
    await gotoStep3(page);

    await page.getByTestId('wizard-next').click();

    await expect(page.getByTestId('wizard-step-3')).toBeVisible();
    await expect(page.getByTestId('wizard-step-3').getByRole('alert')).not.toHaveCount(0);
  });

  test('les blocs répétables ajoutent et suppriment des lignes', async ({ page }) => {
    await gotoStep3(page);

    await expect(page.getByTestId('autres-diplomes-row-0')).toHaveCount(0);

    await page.getByTestId('autres-diplomes-add').click();
    await expect(page.getByTestId('autres-diplomes-row-0')).toBeVisible();

    await page.getByTestId('autres-diplomes-0-intitule').fill('DESS Finances publiques');
    await page.getByTestId('autres-diplomes-0-etablissement').fill('ENAM');
    await page.getByTestId('autres-diplomes-0-annee').fill('2019');

    await page.getByTestId('autres-diplomes-add').click();
    await expect(page.getByTestId('autres-diplomes-row-1')).toBeVisible();

    await page.getByTestId('autres-diplomes-remove-1').click();
    await expect(page.getByTestId('autres-diplomes-row-1')).toHaveCount(0);
    await expect(page.getByTestId('autres-diplomes-0-intitule')).toHaveValue('DESS Finances publiques');

    await page.getByTestId('formations-pro-add').click();
    await expect(page.getByTestId('formations-pro-0-centre')).toBeVisible();
    await page.getByTestId('formations-pro-remove-0').click();
    await expect(page.getByTestId('formations-pro-row-0')).toHaveCount(0);
  });

  test('une ligne incomplète bloque le passage à l’étape suivante', async ({ page }) => {
    await gotoStep3(page);
    await fillDiplomes(page);
    await page.getByTestId('step3-statut-actuel').selectOption('Etudiant');
    await page.getByLabel('Comment avez-vous connu le PSSFP ?').selectOption('Site officiel du PSSFP');

    await page.getByTestId('autres-diplomes-add').click();
    await page.getByTestId('autres-diplomes-0-intitule').fill('DESS');

    await page.getByTestId('wizard-next').click();

    await expect(page.getByTestId('wizard-step-3')).toBeVisible();
    await expect(page.getByTestId('autres-diplomes-0-etablissement')).toHaveAttribute('aria-invalid', 'true');
  });

  test('un bloc répétable complet laisse passer à l’étape suivante', async ({ page }) => {
    await gotoStep3(page);
    await fillDiplomes(page);
    await page.getByTestId('step3-statut-actuel').selectOption('Etudiant');
    await page.getByLabel('Comment avez-vous connu le PSSFP ?').selectOption('Site officiel du PSSFP');

    await page.getByTestId('autres-diplomes-add').click();
    await page.getByTestId('autres-diplomes-0-intitule').fill('DESS');
    await page.getByTestId('autres-diplomes-0-etablissement').fill('ENAM');
    await page.getByTestId('autres-diplomes-0-annee').fill('2019');

    await page.getByTestId('wizard-next').click();

    await expect(page.getByTestId('wizard-step-4')).toBeVisible();
  });

  test('l’étape 3 étendue ne présente aucune violation d’accessibilité', async ({ page }) => {
    await gotoStep3(page);

    await page.getByTestId('step3-domaine-diplome-requis').selectOption('autres');
    await page.getByTestId('autres-diplomes-add').click();
    await page.getByTestId('formations-pro-add').click();

    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();

    // Détail lisible en cas d'échec : sans cela le diff axe est illisible.
    expect(results.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.html).join(' | ')}`)).toEqual([]);
  });
});
