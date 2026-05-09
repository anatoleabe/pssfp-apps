import { test, expect, Page } from '@playwright/test';

const PAYS_FIXTURE = [
  { code_iso: 'CM', nom: 'Cameroun', indicatif: '+237' },
  { code_iso: 'FR', nom: 'France', indicatif: '+33' },
];
const REGIONS = [
  { code: 'CENTRE', nom: 'Centre', quota_admission: 0.15, chef_lieu: 'Yaoundé', order: 2 },
];
const DEPARTEMENTS = [
  { code: 'Mfoundi', nom: 'Mfoundi', chef_lieu: 'Yaoundé', region_code: 'CENTRE' },
];
const SPECIALITES = [{ slug: 'fiscalite-finance-comptabilite-publique', label: 'Fiscalité - Finance - Comptabilité Publique' }];

async function setupMocks(page: Page): Promise<void> {
  await page.route('**/v1/reference/pays', (r) =>
    r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: PAYS_FIXTURE }) }),
  );
  await page.route('**/v1/reference/specialites', (r) =>
    r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: SPECIALITES }) }),
  );
  await page.route('**/v1/reference/regions-cameroun', (r) =>
    r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: REGIONS }) }),
  );
  await page.route('**/v1/reference/departements-cameroun**', (r) =>
    r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: DEPARTEMENTS }) }),
  );
}

async function goToStep2(page: Page): Promise<void> {
  await page.goto('/inscription');
  await page.getByTestId('step1-specialite').click();
  await page.getByRole('option', { name: /Fiscalité/i }).click();
  await page.getByTestId('step1-prenom').fill('Test');
  await page.getByTestId('step1-nom').fill('User');
  await page.getByTestId('step1-date-naissance').fill('1995-06-15');
  await page.getByTestId('wizard-next').click();
  await expect(page.getByTestId('wizard-step-2')).toBeVisible();
}

test.describe('PaysRegionDepartementSelect cascading', () => {
  test.beforeEach(async ({ page }) => {
    await setupMocks(page);
  });

  test('shows region/departement selects when pays_residence is CM (default)', async ({ page }) => {
    await goToStep2(page);
    await expect(page.getByTestId('region-select')).toBeVisible();
    await expect(page.getByTestId('departement-select')).toBeVisible();
  });

  test('hides region/departement selects when pays_residence != CM', async ({ page }) => {
    await goToStep2(page);
    // Sélectionner France comme pays_residence.
    await page.getByTestId('pays-residence-select').click();
    await page.getByRole('option', { name: 'France' }).click();
    await expect(page.getByTestId('region-select')).toHaveCount(0);
    await expect(page.getByTestId('departement-select')).toHaveCount(0);
  });
});
