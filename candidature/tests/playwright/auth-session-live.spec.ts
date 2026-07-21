import { expect, test } from '@playwright/test';

const API_URL = process.env.PSSFP_LIVE_E2E_API_URL;

test.describe('Session candidat réelle avec Laravel', () => {
  test.skip(!API_URL, 'Nécessite PSSFP_LIVE_E2E_API_URL et une base de test dédiée.');

  test('enchaîne login, logout et re-login trois fois sans session fantôme', async ({ page, request }) => {
    const suffix = String(Date.now()).slice(-8);
    const localPhone = `6${suffix}`;
    const phone = `+237${localPhone}`;
    const pin = '847291';

    const registration = await request.post(`${API_URL}/auth/candidat/register`, {
      data: {
        phone_e164: phone,
        phone_country: 'CM',
        pin,
        pin_confirmation: pin,
        nom: 'RECETTE',
        prenom: 'Session',
        date_naissance: '1992-04-18',
        cgu: true,
      },
    });
    expect(registration.status()).toBe(201);
    const account = await registration.json() as { token: string };

    const draft = await request.put(`${API_URL}/applications/me`, {
      headers: { Authorization: `Bearer ${account.token}` },
      data: { civilite: 'M.' },
    });
    expect(draft.ok()).toBeTruthy();

    for (let cycle = 1; cycle <= 3; cycle += 1) {
      await page.goto('/login');
      await page.getByTestId('login-phone-input').fill(localPhone);
      await page.getByTestId('login-pin-input').fill(pin);
      await page.getByTestId('login-submit').click();
      await expect(page).toHaveURL(/\/dossier(?:\?|$)/);
      await expect(page.getByTestId('nav-logout')).toBeVisible();

      await page.getByTestId('nav-logout').click();
      await expect(page).toHaveURL(/\/login\?reason=logged_out/);
      await expect(page.getByTestId('login-phone-input')).toBeVisible();

      if (cycle === 1) {
        await page.goto('/inscription');
        await expect(page.getByRole('heading', { name: /Étape 1/ })).toBeVisible();
        await page.goto('/dossier');
        await expect(page).toHaveURL(/\/login\?reason=session_expired/);
      }
    }
  });
});
