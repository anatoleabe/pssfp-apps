import { test, expect, type APIRequestContext, type Page, type Route } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
// Même base que l'app (playwright.config.ts charge .env.local comme `next dev`).
import { API_BASE_URL as API } from '../../lib/api/client';
import { MAX_SEARCH_PAGE, facetHref, readSearchRequest, searchPageHref, searchRequestKey, searchResetHref } from '../../lib/search';

interface Doc { uuid: string; slug: string; type: string; title: string }
interface SearchBody {
  data: Doc[];
  highlights: Record<string, string> | [];
  meta: { total: number; page: number; last_page: number; facets: { type: Record<string, number> | []; access_mode: Record<string, number> | [] } };
}

/** Réponse 429 : la limite de débit de l'API est atteinte (LIBRARY_SEARCH_RATE_LIMIT dans backend/.env). */
const THROTTLED = 'throttled';
const THROTTLED_MESSAGE = 'API limitée (429) : relever LIBRARY_SEARCH_RATE_LIMIT dans backend/.env ou attendre une minute';

/** Recherche directe sur l'API ; null si injoignable, `THROTTLED` si 429. */
async function apiSearch(request: APIRequestContext, qs: string): Promise<SearchBody | typeof THROTTLED | null> {
  try {
    const res = await request.get(`${API}/library/search?${qs}`, { timeout: 5000 });
    if (res.status() === 429) return THROTTLED;
    if (!res.ok()) return null;
    return (await res.json()) as SearchBody;
  } catch {
    return null;
  }
}

const SEARCH_ROUTE = '**/library/search**';
const isSearchRequest = (url: string): boolean => new URL(url).pathname.endsWith('/library/search');
const fr = (n: number): string => new Intl.NumberFormat('fr-FR').format(n);

/* ------------------------------------------------------------------------------------------------
 * Réponse simulée : rend les tests d'interaction indépendants du backend (CI sans API).
 * ---------------------------------------------------------------------------------------------- */

function summary(i: number, type: string, access: 'libre' | 'reserve' | 'payant', title: string): Record<string, unknown> {
  return {
    uuid: `00000000-0000-4000-8000-00000000000${i}`, slug: `doc-simule-${i}`, type, type_label: type,
    title, subtitle: null, year: 2020 + i, language: 'fr', specialite: null, promotion: null,
    authors: ['Auteur Fictif'], mots_cles: [], cover_url: null, access_mode: access, price_xaf: access === 'payant' ? 3500 : null, published_at: null,
  };
}

const MOCK_DOCS = [
  summary(1, 'memoire', 'libre', 'La décentralisation fiscale au Cameroun'),
  summary(2, 'memoire', 'libre', 'Budget-programme et performance'),
  summary(3, 'these', 'reserve', 'Contrôle de l\'exécution du budget'),
];

function mockBody(url: URL): Record<string, unknown> {
  const q = url.searchParams.get('q') ?? '';
  const type = url.searchParams.get('type');
  if (q === 'introuvable') {
    return { data: [], highlights: [], meta: { total: 0, page: 1, per_page: 20, last_page: 1, facets: { type: [], access_mode: [] } } };
  }
  const docs = type ? MOCK_DOCS.filter((d) => d.type === type) : MOCK_DOCS;
  return {
    data: docs,
    highlights: { [String(MOCK_DOCS[0].uuid)]: 'La <mark>décentralisation</mark> fiscale au Cameroun' },
    meta: {
      total: docs.length, page: Number(url.searchParams.get('page') ?? 1), per_page: 20, last_page: type ? 1 : 3,
      facets: type ? { type: { [type]: docs.length }, access_mode: { libre: docs.length } } : { type: { memoire: 2, these: 1 }, access_mode: { libre: 2, reserve: 1 } },
    },
  };
}

async function fulfillMock(route: Route): Promise<void> {
  await route.fulfill({ status: 200, contentType: 'application/json', headers: { 'access-control-allow-origin': '*' }, body: JSON.stringify(mockBody(new URL(route.request().url()))) });
}

/** Contraste WCAG d'une bordure CSS `rgb()`/`rgba()`, composée sur blanc, contre le fond ivoire de la page (cas le plus défavorable). */
function borderContrast(css: string): number {
  const [r = 0, g = 0, b = 0, a = 1] = (css.match(/[\d.]+/g) ?? []).map(Number);
  const lum = (rgb: number[]): number => {
    const [lr = 0, lg = 0, lb = 0] = rgb.map((c) => {
      const v = c / 255;
      return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * lr + 0.7152 * lg + 0.0722 * lb;
  };
  const border = lum([r, g, b].map((c) => c * a + 255 * (1 - a)));
  const ivoire = lum([250, 247, 242]);
  return (Math.max(border, ivoire) + 0.05) / (Math.min(border, ivoire) + 0.05);
}

/** Contraste WCAG d'une couleur CSS `rgb()`/`rgba()` posée sur du blanc. */
async function contrastOnWhite(css: string): Promise<number> {
  const [r = 0, g = 0, b = 0, a = 1] = (css.match(/[\d.]+/g) ?? []).map(Number);
  const lin = (c: number): number => {
    const v = (c * a + 255 * (1 - a)) / 255;
    return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
  };
  const l = 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
  return 1.05 / (l + 0.05);
}

async function axeSerious(page: Page): Promise<string[]> {
  const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
  return results.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious').map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`);
}

/* ------------------------------------------------------------------------------------------------ */

test.describe('Recherche — utilitaires', () => {
  test('readSearchRequest : q nettoyé, deux caractères au moins, page bornée, sans tri', () => {
    expect(readSearchRequest(new URLSearchParams(''))).toBeNull();
    expect(readSearchRequest(new URLSearchParams('q=a'))).toBeNull();
    expect(readSearchRequest(new URLSearchParams('q=%20%20a%20'))).toBeNull();
    expect(readSearchRequest(new URLSearchParams('q=%20tva%20&type=memoire&tri=titre&acces=gratuit&page=2'))).toEqual({ q: 'tva', filters: { type: 'memoire' }, page: 2 });
    expect(readSearchRequest(new URLSearchParams('q=tva&page=9999'))?.page).toBe(MAX_SEARCH_PAGE);
    expect(readSearchRequest(new URLSearchParams('q=tva&page=-1'))?.page).toBe(1);
    expect(readSearchRequest(new URLSearchParams(`q=${'x'.repeat(250)}`))?.q).toHaveLength(200);
  });

  test('searchRequestKey : identique pour des URL équivalentes, relu à l\'identique', () => {
    const a = searchRequestKey(new URLSearchParams('type=memoire&q=tva&tri=titre'));
    expect(a).toBe(searchRequestKey(new URLSearchParams('q=tva&type=memoire')));
    expect(readSearchRequest(new URLSearchParams(a!))).toEqual({ q: 'tva', filters: { type: 'memoire' }, page: 1 });
    expect(searchRequestKey(new URLSearchParams('q=a'))).toBeNull();
  });

  test('searchPageHref et searchResetHref conservent q', () => {
    expect(searchPageHref('tva', { type: 'memoire' }, 1)).toBe('/recherche?type=memoire&q=tva');
    expect(searchPageHref('tva', { type: 'memoire' }, 3)).toBe('/recherche?type=memoire&q=tva&page=3');
    expect(searchResetHref('marchés publics')).toBe('/recherche?q=march%C3%A9s+publics');
  });

  test('facetHref ajoute le filtre (page 1) ou le retire s\'il est déjà appliqué', () => {
    expect(facetHref('tva', { acces: 'libre' }, 'type', 'memoire')).toBe('/recherche?acces=libre&type=memoire&q=tva');
    expect(facetHref('tva', { type: 'memoire', acces: 'libre' }, 'type', 'memoire')).toBe('/recherche?acces=libre&q=tva');
    expect(facetHref('tva', { type: 'these' }, 'type', 'memoire')).toBe('/recherche?type=memoire&q=tva');
    expect(facetHref('tva', {}, 'acces', 'reserve')).toBe('/recherche?acces=reserve&q=tva');
  });
});

test.describe('Recherche — toujours (avec ou sans API)', () => {
  test('sans q : invite, aucune requête de recherche, noindex', async ({ page }) => {
    const searches: string[] = [];
    page.on('request', (r) => { if (isSearchRequest(r.url())) searches.push(r.url()); });
    const res = await page.goto('/recherche');
    expect(res?.status()).toBe(200);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Recherche');
    await expect(page.getByTestId('search-invite')).toContainText('Saisissez au moins deux caractères');
    await expect(page.getByTestId('search-invite').getByRole('link', { name: 'TVA' })).toHaveAttribute('href', '/recherche?q=TVA');
    await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
    // Page jamais indexée : pas de canonique.
    await expect(page.locator('link[rel="canonical"]')).toHaveCount(0);
    await page.waitForTimeout(300);
    expect(searches).toEqual([]);
  });

  test('q d\'un caractère : invite, champ prérempli', async ({ page }) => {
    await page.goto('/recherche?q=a');
    await expect(page.getByTestId('search-invite')).toBeVisible();
    await expect(page.getByRole('searchbox', { name: 'Rechercher dans le catalogue' })).toHaveValue('a');
  });

  test('la requête de recherche part du navigateur', async ({ page }) => {
    const request = page.waitForRequest((r) => isSearchRequest(r.url()) && r.method() === 'GET');
    await page.goto('/recherche?q=budget&type=memoire&tri=titre');
    const url = new URL((await request).url());
    expect(url.searchParams.get('q')).toBe('budget');
    expect(url.searchParams.get('type')).toBe('memoire');
    expect(url.searchParams.has('tri')).toBe(false);
  });

  test('chargement : squelette et aria-busy sur la région des résultats', async ({ page }) => {
    let release: () => void = () => undefined;
    const held = new Promise<void>((resolve) => { release = resolve; });
    await page.route(SEARCH_ROUTE, async (route) => { await held; await fulfillMock(route); });
    await page.goto('/recherche?q=budget');
    const region = page.getByTestId('search-results');
    await expect(region).toHaveAttribute('aria-busy', 'true');
    await expect(page.getByTestId('search-skeleton')).toBeVisible();
    release();
    await expect(region).toHaveAttribute('aria-busy', 'false');
    await expect(page.getByTestId('search-skeleton')).toHaveCount(0);
  });

  test('API injoignable : alerte et « Réessayer » relance la requête', async ({ page }) => {
    let calls = 0;
    await page.route(SEARCH_ROUTE, (route) => { calls += 1; return route.abort(); });
    await page.goto('/recherche?q=budget');
    const error = page.getByTestId('state-error');
    await expect(error).toBeVisible();
    await expect(error).toHaveAttribute('role', 'alert');
    // En dev, le mode strict de React monte l'effet deux fois (première requête annulée) : on compte à partir d'ici.
    const before = calls;
    expect(before).toBeGreaterThanOrEqual(1);
    const retry = page.waitForRequest((r) => isSearchRequest(r.url()));
    await error.getByRole('button', { name: 'Réessayer' }).click();
    await retry;
    await expect.poll(() => calls).toBe(before + 1);
    await expect(page.getByTestId('state-error')).toBeVisible();
    expect(await axeSerious(page)).toEqual([]);
  });

  test('résultats simulés : total annoncé dans une seule région polie, surlignage, cartes', async ({ page }) => {
    await page.route(SEARCH_ROUTE, fulfillMock);
    await page.goto('/recherche?q=budget');
    const count = page.getByTestId('results-count');
    await expect(count).toHaveText('3 résultats pour « budget »');
    await expect(count).toHaveAttribute('aria-live', 'polite');
    await expect(page.getByRole('main').locator('[aria-live], [role="status"], [role="alert"]')).toHaveCount(1);
    const first = page.getByTestId('document-card-doc-simule-1');
    await expect(first.locator('mark')).toHaveText('décentralisation');
    await expect(page.getByTestId('document-card-doc-simule-3')).toBeVisible();
    await expect(page.getByRole('navigation', { name: 'Pagination' }).getByRole('link', { name: 'Page 2', exact: true })).toHaveAttribute('href', '/recherche?q=budget&page=2');
  });

  test('facette : ajoute type= sans changer q, annule la requête précédente ; Retour restaure', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    const failed: string[] = [];
    page.on('requestfailed', (r) => { if (isSearchRequest(r.url())) failed.push(r.url()); });
    await page.route(SEARCH_ROUTE, fulfillMock);
    await page.goto('/recherche?q=budget&page=2');
    await expect(page.getByTestId('results-count')).toHaveText('3 résultats pour « budget », page 2 sur 3');

    const facets = page.getByTestId('facets-desktop');
    const memoires = facets.getByRole('link', { name: /^Mémoires/ });
    await expect(memoires).toHaveAccessibleName(/2 documents/);
    await memoires.click();
    await expect(page).toHaveURL(/\/recherche\?type=memoire&q=budget$/);
    await expect(page.getByTestId('results-count')).toHaveText('2 résultats pour « budget »');
    await expect(page.getByTestId('document-card-doc-simule-3')).toHaveCount(0);
    // Facette appliquée : signalée, et le même lien la retire.
    const active = page.getByTestId('facets-desktop').getByRole('link', { name: /^Mémoires/ });
    await expect(active).toHaveAttribute('aria-current', 'true');
    await expect(active).toHaveAttribute('href', '/recherche?q=budget');
    await expect(page.getByRole('link', { name: 'Retirer les filtres' })).toHaveAttribute('href', '/recherche?q=budget');

    // Requête lente (type=these) abandonnée au retour arrière : sa réponse n'est jamais affichée.
    await page.unroute(SEARCH_ROUTE);
    await page.route(SEARCH_ROUTE, async (route) => {
      if (new URL(route.request().url()).searchParams.get('type') === 'these') return; // jamais servie
      await fulfillMock(route);
    });
    await page.goto('/recherche?q=budget');
    await expect(page.getByTestId('results-count')).toHaveText('3 résultats pour « budget »');
    await page.getByTestId('facets-desktop').getByRole('link', { name: /^Thèses/ }).click();
    await expect(page).toHaveURL(/type=these/);
    await expect(page.getByTestId('search-results')).toHaveAttribute('aria-busy', 'true');
    await page.goBack();
    await expect(page).toHaveURL(/\/recherche\?q=budget$/);
    await expect(page.getByTestId('results-count')).toHaveText('3 résultats pour « budget »');
    await expect.poll(() => failed.some((u) => u.includes('type=these'))).toBe(true);
  });

  test('zéro résultat : message, liens vers les collections, retrait des filtres', async ({ page }) => {
    await page.route(SEARCH_ROUTE, fulfillMock);
    await page.goto('/recherche?q=introuvable&acces=libre');
    const empty = page.getByTestId('state-empty');
    await expect(empty).toContainText('Aucun résultat pour « introuvable »');
    await expect(page.getByTestId('results-count')).toHaveText('Aucun résultat pour « introuvable »');
    await expect(empty.getByRole('link', { name: 'Retirer les filtres' })).toHaveAttribute('href', '/recherche?q=introuvable');
    await expect(empty.getByRole('link', { name: 'Mémoires' })).toHaveAttribute('href', '/collections/memoire');
    await expect(page.getByRole('main').locator('[aria-live], [role="status"], [role="alert"]')).toHaveCount(1);
  });

  test('axe : invite et résultats simulés (panneau mobile ouvert)', async ({ page }) => {
    await page.goto('/recherche');
    expect(await axeSerious(page)).toEqual([]);
    await page.setViewportSize({ width: 375, height: 800 });
    await page.route(SEARCH_ROUTE, fulfillMock);
    await page.goto('/recherche?q=budget');
    await expect(page.getByTestId('results-count')).toHaveText('3 résultats pour « budget »');
    await page.getByTestId('facets-mobile').locator('summary').click();
    expect(await axeSerious(page)).toEqual([]);
  });

  test('aucun défilement horizontal à 320 px', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 640 });
    await page.route(SEARCH_ROUTE, fulfillMock);
    await page.goto('/recherche?q=budget&type=memoire');
    await expect(page.getByTestId('results-count')).toContainText('résultat');
    await page.getByTestId('facets-mobile').locator('summary').click();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);
  });
});

/** Où se trouve le focus : jamais sur body ; dans les facettes, la pagination ou sur le titre des résultats. */
async function focusPlace(page: Page): Promise<{ body: boolean; facets: boolean; pagination: boolean; heading: boolean }> {
  return page.evaluate(() => {
    const a = document.activeElement;
    return {
      body: a === null || a === document.body,
      facets: !!a?.closest('[data-testid="facets-desktop"]'),
      pagination: !!a?.closest('nav[aria-label="Pagination"]'),
      heading: a?.id === 'results-title',
    };
  });
}

test.describe('Recherche — navigation dans les résultats (réponses simulées)', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
  });

  test('facette au clavier : résultats précédents gardés pendant une réponse lente, focus conservé', async ({ page }) => {
    let release: () => void = () => undefined;
    const held = new Promise<void>((resolve) => { release = resolve; });
    await page.route(SEARCH_ROUTE, async (route) => {
      if (new URL(route.request().url()).searchParams.get('type') === 'memoire') await held;
      await fulfillMock(route);
    });
    await page.goto('/recherche?q=budget');
    await expect(page.getByTestId('results-count')).toHaveText('3 résultats pour « budget »');

    await page.getByTestId('facets-desktop').getByRole('link', { name: /^Mémoires/ }).focus();
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/\/recherche\?type=memoire&q=budget$/);
    // Réponse retenue : facettes et cartes restent affichées, la région est occupée, aucun squelette.
    const region = page.getByTestId('search-results');
    await expect(region).toHaveAttribute('aria-busy', 'true');
    await expect(page.getByTestId('facets-desktop')).toBeVisible();
    await expect(page.getByTestId('document-card-doc-simule-3')).toBeVisible();
    await expect(page.getByTestId('search-skeleton')).toHaveCount(0);

    release();
    await expect(page.getByTestId('results-count')).toHaveText('2 résultats pour « budget »');
    await expect(region).toHaveAttribute('aria-busy', 'false');
    const where = await focusPlace(page);
    expect(where.body).toBe(false);
    expect(where.facets || where.heading).toBe(true);
  });

  test('pagination au clavier : focus conservé, ou sur le titre si le lien disparaît ; page annoncée', async ({ page }) => {
    await page.route(SEARCH_ROUTE, fulfillMock);
    await page.goto('/recherche?q=budget');
    await expect(page.getByTestId('results-count')).toHaveText('3 résultats pour « budget »');
    const pagination = page.getByRole('navigation', { name: 'Pagination' });

    await pagination.getByRole('link', { name: 'Page 2', exact: true }).focus();
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/\/recherche\?q=budget&page=2$/);
    await expect(page.getByTestId('results-count')).toHaveText('3 résultats pour « budget », page 2 sur 3');
    let where = await focusPlace(page);
    expect(where.body).toBe(false);
    expect(where.pagination || where.heading).toBe(true);

    // « Page suivante » n'existe plus sur la dernière page : le focus passe au titre « Résultats ».
    await pagination.getByRole('link', { name: 'Page suivante' }).focus();
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/page=3$/);
    await expect(page.getByTestId('results-count')).toHaveText('3 résultats pour « budget », page 3 sur 3');
    await expect(page.getByTestId('results-title')).toBeFocused();
    where = await focusPlace(page);
    expect(where.heading).toBe(true);
  });

  test('réponses dans le désordre : seule la dernière recherche est affichée', async ({ page }) => {
    let holdUnfiltered = false;
    let releaseFirst: () => void = () => undefined;
    const firstHeld = new Promise<void>((resolve) => { releaseFirst = resolve; });
    let firstDone: () => void = () => undefined;
    const firstSettled = new Promise<void>((resolve) => { firstDone = resolve; });
    await page.route(SEARCH_ROUTE, async (route) => {
      const type = new URL(route.request().url()).searchParams.get('type');
      if (type === null && holdUnfiltered) {
        await firstHeld;
        // La requête a pu être annulée entre-temps : la réponse tardive n'a alors plus de destinataire.
        await fulfillMock(route).catch(() => undefined);
        firstDone();
        return;
      }
      await fulfillMock(route);
      if (type === 'memoire' && holdUnfiltered) releaseFirst();
    });
    await page.goto('/recherche?q=budget');
    await expect(page.getByTestId('results-count')).toHaveText('3 résultats pour « budget »');
    await page.getByTestId('facets-desktop').getByRole('link', { name: /^Mémoires/ }).click();
    await expect(page.getByTestId('results-count')).toHaveText('2 résultats pour « budget »');

    // Première recherche (Retour : q=budget) retenue, seconde (Suivant : type=memoire) servie avant elle.
    holdUnfiltered = true;
    await page.goBack();
    await expect(page).toHaveURL(/\/recherche\?q=budget$/);
    await expect(page.getByTestId('search-results')).toHaveAttribute('aria-busy', 'true');
    await page.goForward();
    await expect(page).toHaveURL(/type=memoire/);
    await firstSettled;
    await page.waitForTimeout(200);
    await expect(page.getByTestId('results-count')).toHaveText('2 résultats pour « budget »');
    await expect(page.getByTestId('document-card-doc-simule-1')).toBeVisible();
    await expect(page.getByTestId('document-card-doc-simule-3')).toHaveCount(0);
    await expect(page).toHaveURL(/\/recherche\?type=memoire&q=budget$/);
  });

  test('liens périmés inertes : une facette de l\'ancienne réponse ne navigue pas', async ({ page }) => {
    let release: () => void = () => undefined;
    const held = new Promise<void>((resolve) => { release = resolve; });
    await page.route(SEARCH_ROUTE, async (route) => {
      if (new URL(route.request().url()).searchParams.get('type') === 'memoire') await held;
      await fulfillMock(route);
    });
    await page.goto('/recherche?q=budget');
    await expect(page.getByTestId('results-count')).toHaveText('3 résultats pour « budget »');
    const facets = page.getByTestId('facets-desktop');
    await facets.getByRole('link', { name: /^Mémoires/ }).click();
    await expect(page).toHaveURL(/type=memoire/);
    await expect(facets.locator('[inert]')).toHaveCount(1);
    await expect(page.locator('[inert]:has(nav[aria-label="Pagination"])')).toHaveCount(1);
    // Clic forcé (l'élément inerte n'est pas actionnable) : aucune navigation.
    await facets.getByRole('link', { name: /^Thèses/ }).click({ force: true });
    await page.waitForTimeout(300);
    await expect(page).toHaveURL(/\/recherche\?type=memoire&q=budget$/);
    release();
    await expect(page.getByTestId('results-count')).toHaveText('2 résultats pour « budget »');
    await expect(facets.locator('[inert]')).toHaveCount(0);
  });

  test('retour à l\'invite : les anciens résultats ne réapparaissent pas sous une nouvelle requête', async ({ page }) => {
    let release: () => void = () => undefined;
    const held = new Promise<void>((resolve) => { release = resolve; });
    await page.route(SEARCH_ROUTE, async (route) => {
      if (new URL(route.request().url()).searchParams.get('q') === 'TVA') await held;
      await fulfillMock(route);
    });
    await page.goto('/recherche?q=budget');
    await expect(page.getByTestId('results-count')).toHaveText('3 résultats pour « budget »');
    await page.getByRole('banner').getByRole('link', { name: 'Recherche', exact: true }).click();
    await expect(page.getByTestId('search-invite')).toBeVisible();
    await page.getByTestId('search-invite').getByRole('link', { name: 'TVA' }).click();
    await expect(page).toHaveURL(/q=TVA/);
    await expect(page.getByTestId('search-skeleton')).toBeVisible();
    await expect(page.getByTestId('document-card-doc-simule-1')).toHaveCount(0);
    release();
    await expect(page.getByTestId('results-count')).toHaveText('3 résultats pour « TVA »');
  });

  test('focus : rien au premier chargement ; Retour ne fait pas défiler la page vers le titre', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 300 });
    await page.route(SEARCH_ROUTE, fulfillMock);
    await page.goto('/recherche?q=budget');
    await expect(page.getByTestId('results-count')).toHaveText('3 résultats pour « budget »');
    expect((await focusPlace(page)).body).toBe(true);

    // Clic programmatique en haut de page : l'entrée d'historique quittée garde scrollY = 0,
    // position où le titre « Résultats » est hors de la fenêtre (300 px de haut).
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.getByTestId('facets-desktop').getByRole('link', { name: /^Mémoires/ }).evaluate((a: HTMLElement) => a.click());
    await expect(page.getByTestId('results-count')).toHaveText('2 résultats pour « budget »');
    // Focus perdu : au retour, le repli le rendra au titre. On note la position de défilement à ce moment.
    await page.evaluate(() => {
      (document.activeElement as HTMLElement | null)?.blur();
      window.scrollTo(0, 0);
      const w = window as unknown as { focusScroll: number | null };
      w.focusScroll = null;
      document.addEventListener('focusin', () => { w.focusScroll = window.scrollY; });
    });
    await page.goBack();
    await expect(page.getByTestId('results-count')).toHaveText('3 résultats pour « budget »');
    await page.waitForTimeout(300);
    const where = await focusPlace(page);
    expect(where.body || where.heading).toBe(true);
    // Le navigateur restaure lui-même la position de l'entrée d'historique ; le focus ne la modifie pas.
    const { focusScroll, scrollY } = await page.evaluate(() => ({ focusScroll: (window as unknown as { focusScroll: number | null }).focusScroll, scrollY: window.scrollY }));
    expect({ focusScroll, scrollY }).toEqual({ focusScroll: where.heading ? 0 : null, scrollY: 0 });
  });

  test('portée visible : puces retirables ; une nouvelle requête garde les filtres et repart en page 1', async ({ page }) => {
    await page.route(SEARCH_ROUTE, fulfillMock);
    await page.goto('/recherche?q=budget&type=memoire&acces=libre&page=2');
    const scope = page.getByRole('list', { name: 'Portée de la recherche' });
    await expect(scope.getByRole('link', { name: 'Retirer le filtre Dans : Mémoires' })).toHaveAttribute('href', '/recherche?acces=libre&q=budget');
    await expect(scope.getByRole('link', { name: 'Retirer le filtre Accès : Libre' })).toHaveAttribute('href', '/recherche?type=memoire&q=budget');
    await expect(scope).toContainText('Dans : Mémoires');

    const box = page.getByRole('searchbox', { name: 'Rechercher dans le catalogue' });
    await box.fill('tva');
    await box.press('Enter');
    await expect(page).toHaveURL(/q=tva/);
    const url = new URL(page.url());
    expect(Object.fromEntries(url.searchParams)).toEqual({ type: 'memoire', acces: 'libre', q: 'tva' });
    await expect(page.getByRole('list', { name: 'Portée de la recherche' }).getByRole('link')).toHaveCount(2);

    await page.getByRole('link', { name: 'Retirer le filtre Dans : Mémoires' }).click();
    await expect(page).toHaveURL(/\/recherche\?acces=libre&q=tva$/);
    await expect(page.getByRole('list', { name: 'Portée de la recherche' }).getByRole('link')).toHaveCount(1);
  });

  test('spécialité ou mot-clé sans intitulé : ni puce ni report sur une nouvelle requête', async ({ page }) => {
    await page.route(SEARCH_ROUTE, fulfillMock);
    await page.goto('/recherche?q=budget&type=memoire&specialite=inconnue&mot_cle=slug-inconnu');
    const form = page.getByTestId('search-form-hero');
    await expect(form.locator('input[type="hidden"]')).toHaveCount(1);
    await expect(form.locator('input[type="hidden"][name="type"]')).toHaveValue('memoire');
    await expect(page.getByRole('list', { name: 'Portée de la recherche' }).getByRole('link')).toHaveCount(1);
    await expect(page.getByText('inconnue')).toHaveCount(0);
    await expect(page.getByText('slug-inconnu')).toHaveCount(0);
  });

  test('tiroir mobile : groupes nommés sans saut de niveau de titre ; ouverture conservée', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 800 });
    let release: () => void = () => undefined;
    const held = new Promise<void>((resolve) => { release = resolve; });
    await page.route(SEARCH_ROUTE, async (route) => {
      if (new URL(route.request().url()).searchParams.get('type') === 'memoire') await held;
      await fulfillMock(route);
    });
    await page.goto('/recherche?q=budget');
    const drawer = page.getByTestId('facets-mobile');
    await drawer.locator('summary').click();
    await expect(drawer.locator('h3')).toHaveCount(0);
    await expect(drawer.getByRole('group', { name: 'Type de document' })).toBeVisible();
    await drawer.getByRole('link', { name: /^Mémoires/ }).click();
    await expect(page).toHaveURL(/type=memoire/);
    await expect(drawer).toHaveAttribute('open', '');
    release();
    await expect(page.getByTestId('results-count')).toHaveText('2 résultats pour « budget »');
    await expect(drawer).toHaveAttribute('open', '');
  });

  test('case de facette non cochée : bordure ≥ 3:1 sur blanc', async ({ page }) => {
    await page.route(SEARCH_ROUTE, fulfillMock);
    await page.goto('/recherche?q=budget');
    const link = page.getByTestId('facets-desktop').getByRole('link', { name: /^Mémoires/ });
    await expect(link).toBeVisible();
    const box = link.locator('span[aria-hidden="true"]').first();
    expect(await box.evaluate((el) => getComputedStyle(el).borderTopColor).then(contrastOnWhite)).toBeGreaterThanOrEqual(3);
  });

  test('invite : puces de collections avec un contour au repos ≥ 3:1', async ({ page }) => {
    await page.goto('/recherche');
    // Seules les puces ont un contour (les suggestions sont des liens soulignés).
    const borders = await page.getByTestId('search-invite').getByRole('link').evaluateAll((els) =>
      els.map((el) => getComputedStyle(el)).filter((st) => st.borderTopWidth !== '0px').map((st) => st.borderTopColor),
    );
    expect(borders.length).toBeGreaterThan(0);
    for (const border of borders) expect(borderContrast(border), border).toBeGreaterThanOrEqual(3);
  });

  test('puces de portée : contour au repos ≥ 3:1', async ({ page }) => {
    await page.route(SEARCH_ROUTE, fulfillMock);
    await page.goto('/recherche?q=budget&type=memoire&acces=libre');
    const chips = page.getByTestId('search-scope').getByRole('link');
    await expect(chips).toHaveCount(2);
    for (const border of await chips.evaluateAll((els) => els.map((el) => getComputedStyle(el).borderTopColor))) {
      expect(borderContrast(border), border).toBeGreaterThanOrEqual(3);
    }
  });

  test('sans facette : pas de colonne de filtres', async ({ page }) => {
    await page.route(SEARCH_ROUTE, fulfillMock);
    await page.goto('/recherche?q=introuvable');
    await expect(page.getByTestId('state-empty')).toBeVisible();
    await expect(page.getByTestId('facets-desktop')).toHaveCount(0);
    await expect(page.getByTestId('facets-mobile')).toHaveCount(0);
  });
});

test.describe('Recherche — données de l\'API', () => {
  let budget: SearchBody | null = null;
  let throttled = false;

  test.beforeAll(async ({ request }) => {
    const r = await apiSearch(request, 'q=budget');
    throttled = r === THROTTLED;
    budget = r === THROTTLED ? null : r;
  });

  test.beforeEach(() => {
    expect(throttled, THROTTLED_MESSAGE).toBe(false);
    test.skip(budget === null, 'API injoignable : tests de données ignorés');
  });

  test('les cartes et le total sont ceux de l\'API', async ({ page }) => {
    await page.goto('/recherche?q=budget');
    const total = budget!.meta.total;
    await expect(page.getByTestId('results-count')).toContainText(total === 0 ? 'Aucun résultat' : fr(total));
    const ids = await page.getByTestId('search-results').locator('article[data-testid^="document-card-"]').evaluateAll((els) => els.map((el) => el.getAttribute('data-testid')));
    expect(ids).toEqual(budget!.data.map((d) => `document-card-${d.slug}`));
  });

  test('surlignage <mark> quand le moteur en renvoie (faute tolérée par Meilisearch)', async ({ page, request }) => {
    let chosen: { q: string; body: SearchBody } | null = null;
    for (const q of ['decentralisaton', 'decentralisation']) {
      const body = await apiSearch(request, `q=${q}`);
      expect(body, THROTTLED_MESSAGE).not.toBe(THROTTLED);
      if (body && body !== THROTTLED && body.data.length > 0) { chosen = { q, body }; break; }
    }
    test.skip(chosen === null, 'aucun document pour « décentralisation »');
    await page.goto(`/recherche?q=${chosen!.q}`);
    await expect(page.getByTestId('search-results').locator('article').first()).toBeVisible();
    const highlights = Array.isArray(chosen!.body.highlights) ? {} : chosen!.body.highlights;
    const marked = chosen!.body.data.find((d) => (highlights[d.uuid] ?? '').includes('<mark>'));
    // Repli PostgreSQL : pas de surlignage, rien à vérifier de plus.
    if (marked) await expect(page.getByTestId(`document-card-${marked.slug}`).locator('mark').first()).toBeVisible();
  });

  test('facette de type réelle : URL mise à jour, Retour restaure', async ({ page }) => {
    const facets = Array.isArray(budget!.meta.facets.type) ? {} : budget!.meta.facets.type;
    const type = Object.keys(facets)[0];
    test.skip(!type, 'aucune facette de type');
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/recherche?q=budget');
    await expect(page.getByTestId('search-results')).toHaveAttribute('aria-busy', 'false');
    await page.getByTestId('facets-desktop').locator(`a[href="/recherche?type=${type}&q=budget"]`).click();
    await expect(page).toHaveURL(new RegExp(`type=${type}`));
    await expect(page.getByTestId('results-count')).toContainText(fr(facets[type]));
    await page.goBack();
    await expect(page).toHaveURL(/\/recherche\?q=budget$/);
    await expect(page.getByTestId('results-count')).toContainText(fr(budget!.meta.total));
  });

  test('axe avec résultats réels', async ({ page }) => {
    await page.goto('/recherche?q=budget');
    await expect(page.getByTestId('search-results')).toHaveAttribute('aria-busy', 'false');
    expect(await axeSerious(page)).toEqual([]);
  });

  // Captures d'écran pour la revue visuelle : uniquement si SCREENSHOT_DIR est défini (jamais en CI).
  for (const width of [320, 1440]) {
    test(`@visuel capture ${width} px`, async ({ page }) => {
      test.skip(!process.env.SCREENSHOT_DIR || !!process.env.CI, 'captures manuelles uniquement');
      await page.setViewportSize({ width, height: 900 });
      await page.goto('/recherche?q=budget');
      await expect(page.getByTestId('search-results')).toHaveAttribute('aria-busy', 'false');
      await page.waitForLoadState('networkidle');
      await page.screenshot({ path: `${process.env.SCREENSHOT_DIR}/recherche-budget-${width}.png`, fullPage: true });
    });
  }
});
