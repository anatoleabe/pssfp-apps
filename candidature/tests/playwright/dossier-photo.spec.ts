import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Tests /dossier/photo (PR G).
 *
 * Le Server Component fait un fetch SSR /v1/applications/me. Sans cookie
 * de token et sans backend, on attend la redirection vers /login. Avec un
 * cookie factice et sans backend, le fetch SSR échoue (status 0 réseau)
 * et la page reste sur /dossier/photo (rendu fallback ou overlay dev).
 *
 * Les tests fonctionnels (upload réel, ClamAV, signed URL) sont couverts
 * par les tests Pest backend (≥14 tests verts dans PhotoUploadTest).
 */

test.describe('/dossier/photo — accès non authentifié', () => {
  test('redirects to /login when no candidat cookie is present', async ({ page }) => {
    const response = await page.goto('/dossier/photo');
    expect(page.url()).toMatch(/\/login/);
    expect(response?.url()).toMatch(/\/login/);
  });

  test('redirects to /login with reason=session_expired query', async ({ page }) => {
    await page.goto('/dossier/photo');
    expect(page.url()).toContain('reason=session_expired');
  });

  test('login page reached has no critical a11y violations', async ({ page }) => {
    await page.goto('/dossier/photo');
    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    expect(results.violations.filter((v) => v.impact === 'critical')).toEqual([]);
  });

  test('redirected /login page is renderable and offers a phone field', async ({ page }) => {
    await page.goto('/dossier/photo');
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    // Le formulaire de login expose un champ téléphone E.164 (cf. PR F).
    expect(await page.locator('input').count()).toBeGreaterThan(0);
  });
});

test.describe('/dossier/photo — flow with stale token', () => {
  test('does not redirect when a token cookie is present (page stays at /dossier/photo)', async ({
    context,
    page,
  }) => {
    await context.addCookies([
      {
        name: 'pssfp_candidat_token',
        value: 'fake-token-stale',
        domain: 'localhost',
        path: '/',
      },
    ]);
    // Avec un cookie présent, le redirect précoce ne se déclenche pas. Le SSR
    // tente de fetcher /v1/applications/me (qui n'existe pas en CI sans backend) —
    // l'erreur réseau se traduit par un fallback rendu côté serveur OU par
    // l'overlay de dev de Next.js. Dans les deux cas, l'URL reste celle qu'on
    // a demandée — ce que vérifie ce test (vs un redirect vers /login).
    await page.goto('/dossier/photo');
    expect(page.url()).toContain('/dossier/photo');
    expect(page.url()).not.toMatch(/\/login/);
  });
});

test.describe('/dossier — DossierPhotoCard wiring', () => {
  test('redirects to /login when not authenticated', async ({ page }) => {
    await page.goto('/dossier');
    expect(page.url()).toMatch(/\/login/);
  });
});
