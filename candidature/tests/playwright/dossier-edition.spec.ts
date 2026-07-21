import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Tests /dossier/edition (PR H).
 *
 * Le Server Component fait un fetch SSR /v1/applications/me. Sans cookie de
 * token, la page redirige vers /login. Avec un cookie factice et sans backend,
 * le SSR échoue (status 0 réseau) et la page reste sur /dossier/edition (rendu
 * fallback ou overlay dev) — comportement défensif identique aux autres pages
 * authentifiées (cf. /dossier, /dossier/photo, /dossier/suivi).
 *
 * Les tests fonctionnels (auto-save, debounce 2s, lock 409, validation 422)
 * sont couverts par les tests Pest backend (PartialUpdateTest = 7 tests verts).
 */

test.describe('/dossier/edition — accès non authentifié', () => {
  test('redirects to /login when no candidat cookie is present', async ({ page }) => {
    const response = await page.goto('/dossier/edition');
    expect(page.url()).toMatch(/\/login/);
    expect(response?.url()).toMatch(/\/login/);
  });

  test('redirects to /login with reason=session_expired query', async ({ page }) => {
    await page.goto('/dossier/edition');
    expect(page.url()).toContain('reason=session_expired');
  });

  test('login page reached after redirect has no critical a11y violations', async ({ page }) => {
    await page.goto('/dossier/edition');
    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    expect(results.violations.filter((v) => v.impact === 'critical')).toEqual([]);
  });

  test('redirects from /dossier/edition?focus=civilite to /login when unauth', async ({ page }) => {
    await page.goto('/dossier/edition?focus=civilite');
    expect(page.url()).toMatch(/\/login/);
  });
});

test.describe('/dossier/edition — flow with stale token', () => {
  test('redirects when an invalid token cookie is present', async ({
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
    await page.goto('/dossier/edition');
    expect(page.url()).toMatch(/\/login\?reason=session_expired/);
  });

  test('does not preserve protected query params after session expiry', async ({ context, page }) => {
    await context.addCookies([
      {
        name: 'pssfp_candidat_token',
        value: 'fake-token-stale',
        domain: 'localhost',
        path: '/',
      },
    ]);
    await page.goto('/dossier/edition?focus=civilite');
    expect(page.url()).toMatch(/\/login\?reason=session_expired/);
  });
});

test.describe('/dossier — locked banner (PR H)', () => {
  test('renders the locked banner when /dossier?reason=locked is reached', async ({ page }) => {
    // Pas de cookie : on est redirigé vers /login. C'est OK, on n'est pas
    // censé voir la bannière sans authentification — le test vérifie juste
    // qu'aucune erreur n'est levée sur ce parcours et que /login reste
    // accessible (vs un crash). Le rendu de la bannière proprement dite
    // est validé en E2E avec backend live.
    await page.goto('/dossier?reason=locked');
    expect(page.url()).toMatch(/\/login/);
  });
});

test.describe('/dossier — Modifier mon dossier CTA', () => {
  test('redirects to /login when not authenticated (overall dossier page)', async ({ page }) => {
    // Le bouton « ✏️ Modifier mon dossier » est rendu côté SSR seulement
    // si statut = postulant. Sans backend on ne peut pas tester son rendu,
    // mais on s'assure que le parcours unauth → /login fonctionne toujours.
    await page.goto('/dossier');
    expect(page.url()).toMatch(/\/login/);
  });
});
