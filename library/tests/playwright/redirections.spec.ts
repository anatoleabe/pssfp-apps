import { test, expect, type APIRequestContext } from '@playwright/test';
// Même base que l'app (playwright.config.ts charge .env.local comme `next dev`).
import { API_BASE_URL as API } from '../../lib/api/client';
import { legacyLookupPath, lookupCandidates, safeRedirectTarget } from '../../lib/redirects';

/** Redirection de démonstration créée par `LibraryDemoSeeder` (backend). */
const LEGACY_PATH = '/index.php/textes-et-lois';
const LEGACY_TARGET = '/collections/texte_loi';
/** Ancienne URL à query string (forme de stockage du Plan 3), créée par `LibraryDemoSeeder`. */
const LEGACY_QUERY_PATH = '/index.php?option=com_docman&view=list&slug=memoires';
const LEGACY_QUERY_TARGET = '/collections/memoire';

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

test.describe('Anciennes URL — validation (fonctions pures)', () => {
  const BASE = new URL('https://library.pssfp.org/index.php/ancien');

  test('seul un chemin interne du même site est accepté', () => {
    expect(safeRedirectTarget('/ok', BASE)?.href).toBe('https://library.pssfp.org/ok');
    expect(safeRedirectTarget('/collections/texte_loi', BASE)?.href).toBe('https://library.pssfp.org/collections/texte_loi');
    expect(safeRedirectTarget('/documents/x?y=1', BASE)?.href).toBe('https://library.pssfp.org/documents/x?y=1');
    expect(safeRedirectTarget('/collections/memoire?acces=libre', BASE)?.href).toBe('https://library.pssfp.org/collections/memoire?acces=libre');
    // Points non isolés : pas des segments de chemin.
    expect(safeRedirectTarget('/documents/rapport-v1.2', BASE)?.pathname).toBe('/documents/rapport-v1.2');
    expect(safeRedirectTarget('/documents/a...b', BASE)?.pathname).toBe('/documents/a...b');
    // Cible reconstruite (chemin + query) : jamais la chaîne brute, jamais de fragment.
    expect(safeRedirectTarget('/documents/x?y=1#z', BASE)?.href).toBe('https://library.pssfp.org/documents/x?y=1');
  });

  test('cibles piégées refusées (contrôle, barre inverse, //, segments . et .. même encodés)', () => {
    const traps = [
      '/\t/evil.com', '//evil.com', '/\\evil', '/\\/evil.com', 'https://evil.com/x', 'evil.com', '',
      '/ok\r\nSet-Cookie: x=1', '/ok\u0000', '/ok\u007F',
      // `new URL` normalise ces chemins en `//evil.com` (même origine), que Next relativise en `Location: //evil.com`.
      '/a/..//evil.com', '/%2e%2e//evil.com', '/./\\evil.com', '/x/../\\evil.com',
      '/%2e/x', '/a/%2E%2E/b', '/a/.', '/a/..?x=1', '/collections\\memoire',
    ];
    for (const to of traps) expect(safeRedirectTarget(to, BASE), JSON.stringify(to)).toBeNull();
    expect(safeRedirectTarget(42, BASE)).toBeNull();
    expect(safeRedirectTarget(null, BASE)).toBeNull();
  });

  test('lookupCandidates : avec query d\'abord, chemin seul en repli (paramètres de suivi)', () => {
    expect(lookupCandidates({ pathname: '/index.php/textes-et-lois', search: '?utm_source=x' })).toEqual(['/index.php/textes-et-lois?utm_source=x', '/index.php/textes-et-lois']);
    expect(lookupCandidates({ pathname: '/index.php', search: '?option=com_docman&fbclid=z' })).toEqual(['/index.php?option=com_docman&fbclid=z', '/index.php']);
    expect(lookupCandidates({ pathname: '/index.php/textes-et-lois', search: '' })).toEqual(['/index.php/textes-et-lois']);
    expect(lookupCandidates({ pathname: '/anciens/page.html', search: '?gclid=1' })).toEqual(['/anciens/page.html']);
  });

  test('/index.php à query string : la query string fait partie du chemin cherché', () => {
    expect(legacyLookupPath({ pathname: '/index.php', search: '?option=com_docman&view=list' })).toBe('/index.php?option=com_docman&view=list');
    expect(legacyLookupPath({ pathname: '/index.php', search: '' })).toBe('/index.php');
    // Forme canonique des fiches Joomla : /index.php/component/…?id=N.
    expect(legacyLookupPath({ pathname: '/index.php/component/abook/book', search: '?id=12' })).toBe('/index.php/component/abook/book?id=12');
    expect(legacyLookupPath({ pathname: '/index.php/textes-et-lois', search: '' })).toBe('/index.php/textes-et-lois');
    // Hors /index.php, la query string (pistage, pagination) est ignorée.
    expect(legacyLookupPath({ pathname: '/anciens/page.html', search: '?utm_source=x' })).toBe('/anciens/page.html');
    expect(legacyLookupPath({ pathname: '/collections/memoire', search: '?page=2' })).toBe('/collections/memoire');
  });
});

test.describe('Anciennes URL — toujours (avec ou sans API)', () => {
  test('chemin ancien inconnu : 404, sans redirection', async ({ request }) => {
    const res = await request.get('/index.php/inconnu-de-la-bibliotheque', { maxRedirects: 0 });
    expect(res.status()).toBe(404);
    expect(res.headers().location).toBeUndefined();
  });

  test('/index.php?option=… inconnu : 404, sans redirection ni erreur', async ({ request }) => {
    const res = await request.get('/index.php?option=com_inconnu&id=1', { maxRedirects: 0 });
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

  test('ancienne URL /index.php?… : la query string sert à la recherche de la redirection', async ({ request }) => {
    const known = await request.get(`${API}/library/redirects?path=${encodeURIComponent(LEGACY_QUERY_PATH)}`, { timeout: 3000 }).catch(() => null);
    // 429 : échec explicite, comme les autres specs (jamais un test ignoré à tort).
    if (known?.status() === 429) throw new Error(THROTTLED_MESSAGE);
    test.skip(known === null || !known.ok(), 'redirection à query string absente (relancer LibraryDemoSeeder)');
    const res = await request.get(LEGACY_QUERY_PATH, { maxRedirects: 0 });
    expect(res.status()).toBe(301);
    expect(new URL(res.headers().location ?? '', 'http://x').pathname).toBe(LEGACY_QUERY_TARGET);
  });

  test('la redirection aboutit à une page 200', async ({ request }) => {
    const res = await request.get(LEGACY_PATH);
    expect(res.status()).toBe(200);
    expect(new URL(res.url()).pathname).toBe(LEGACY_TARGET);
  });
});
