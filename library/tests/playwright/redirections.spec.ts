import { test, expect, type APIRequestContext } from '@playwright/test';
// Même base que l'app (playwright.config.ts charge .env.local comme `next dev`).
import { API_BASE_URL as API } from '../../lib/api/client';

/** Redirection de démonstration créée par `LibraryDemoSeeder` (backend). */
const LEGACY_PATH = '/index.php/textes-et-lois';
const LEGACY_TARGET = '/collections/texte_loi';

const THROTTLED = 'throttled';
const THROTTLED_MESSAGE = 'API limitée (429) : relever LIBRARY_RATE_LIMIT dans backend/.env ou attendre une minute';

/** La redirection de démonstration existe-t-elle dans l'API ? (null : API injoignable ou base non seedée). */
async function seededRedirect(request: APIRequestContext): Promise<boolean | typeof THROTTLED | null> {
  try {
    const res = await request.get(`${API}/library/redirects?path=${encodeURIComponent(LEGACY_PATH)}`, { timeout: 3000 });
    if (res.status() === 429) return THROTTLED;
    if (!res.ok()) return null;
    const { data } = (await res.json()) as { data: { to_path: string } };
    return data.to_path === LEGACY_TARGET;
  } catch {
    return null;
  }
}

test.describe('Anciennes URL — toujours (avec ou sans API)', () => {
  test('chemin ancien inconnu : 404, sans redirection', async ({ request }) => {
    const res = await request.get('/index.php/inconnu-de-la-bibliotheque', { maxRedirects: 0 });
    expect(res.status()).toBe(404);
    expect(res.headers().location).toBeUndefined();
  });

  test('une route de l\'app n\'est jamais redirigée', async ({ request }) => {
    for (const path of ['/recherche', '/recherche?q=budget', '/a-propos', '/robots.txt', '/sitemap.xml', '/icon.png']) {
      const res = await request.get(path, { maxRedirects: 0 });
      expect(res.status(), path).toBe(200);
      expect(res.headers().location, path).toBeUndefined();
    }
  });
});

test.describe('Anciennes URL — redirections de l\'API', () => {
  let state: boolean | typeof THROTTLED | null = null;

  test.beforeAll(async ({ request }) => {
    state = await seededRedirect(request);
  });

  test.beforeEach(() => {
    if (state === THROTTLED) throw new Error(THROTTLED_MESSAGE);
    test.skip(state !== true, 'API injoignable ou redirection de démonstration absente (LibraryDemoSeeder)');
  });

  test('ancienne URL Joomla : 301 vers la collection', async ({ request }) => {
    const res = await request.get(LEGACY_PATH, { maxRedirects: 0 });
    expect(res.status()).toBe(301);
    expect(new URL(res.headers().location ?? '', 'http://x').pathname).toBe(LEGACY_TARGET);
  });

  test('la redirection aboutit à une page 200', async ({ request }) => {
    const res = await request.get(LEGACY_PATH);
    expect(res.status()).toBe(200);
    expect(new URL(res.url()).pathname).toBe(LEGACY_TARGET);
  });
});
