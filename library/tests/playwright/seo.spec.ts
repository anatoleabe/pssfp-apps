import { test, expect, type APIRequestContext, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
// Même base que l'app (playwright.config.ts charge .env.local comme `next dev`).
import { API_BASE_URL as API } from '../../lib/api/client';
import { siteUrl } from '../../lib/site';

const ORIGIN = siteUrl().origin;
const BYPASS_DATA_CACHE = { 'cache-control': 'no-cache' };

async function apiReachable(request: APIRequestContext): Promise<boolean> {
  try {
    return (await request.get(`${API}/library/stats`, { timeout: 3000 })).ok();
  } catch {
    return false;
  }
}

async function seriousAxeViolations(page: Page): Promise<string[]> {
  const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
  return results.violations
    .filter((v) => v.impact === 'critical' || v.impact === 'serious')
    .map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`);
}

/** Analyse le XML dans le navigateur : `<loc>` du sitemap, ou null si le document est invalide. */
async function sitemapLocs(page: Page, xml: string): Promise<string[] | null> {
  return page.evaluate((source) => {
    const doc = new DOMParser().parseFromString(source, 'application/xml');
    if (doc.getElementsByTagName('parsererror').length > 0 || doc.documentElement.localName !== 'urlset') return null;
    return Array.from(doc.getElementsByTagName('loc'), (n) => (n.textContent ?? '').trim());
  }, xml);
}

test.describe('SEO — toujours (avec ou sans API)', () => {
  test('robots.txt : sitemap déclaré, /dev/ et /recherche exclus', async ({ request }) => {
    const res = await request.get('/robots.txt');
    expect(res.status()).toBe(200);
    const body = await res.text();
    expect(body).toMatch(/^Allow: \/$/m);
    expect(body).toMatch(/^Disallow: \/dev\/$/m);
    expect(body).toMatch(/^Disallow: \/recherche$/m);
    expect(body).toContain(`Sitemap: ${ORIGIN}/sitemap.xml`);
  });

  test('sitemap.xml : XML valide, accueil, à propos et thèmes présents, recherche absente', async ({ page, request }) => {
    const res = await request.get('/sitemap.xml');
    expect(res.status()).toBe(200);
    expect(res.headers()['content-type']).toContain('xml');
    const locs = await sitemapLocs(page, await res.text());
    expect(locs).not.toBeNull();
    expect(locs).toContain(`${ORIGIN}/`);
    expect(locs).toContain(`${ORIGIN}/a-propos`);
    expect(locs).toContain(`${ORIGIN}/themes`);
    expect(locs!.some((l) => l.includes('/recherche') || l.includes('/dev'))).toBe(false);
  });

  test('accueil : meta description', async ({ page }) => {
    await page.setExtraHTTPHeaders(BYPASS_DATA_CACHE);
    await page.goto('/');
    await expect(page.locator('meta[name="description"]')).toHaveAttribute('content', /.{30,}/);
  });

  test('à propos : h1, canonique, description et règles d\'accès', async ({ page }) => {
    await page.goto('/a-propos');
    await expect(page.locator('h1')).toHaveCount(1);
    await expect(page.locator('h1')).toContainText('propos');
    await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', `${ORIGIN}/a-propos`);
    await expect(page.locator('meta[name="description"]')).toHaveAttribute('content', /.{30,}/);
    const rules = page.getByTestId('about-access-rules');
    for (const label of ['Libre', 'Réservé', 'Payant']) await expect(rules.getByText(label, { exact: true })).toBeVisible();
    const contact = page.getByTestId('about-contact').getByRole('link', { name: /page contact/i });
    await expect(contact).toHaveAttribute('href', /^https?:\/\/.+\/contact$/);
  });

  test('à propos : axe sans violation critique ou grave', async ({ page }) => {
    await page.goto('/a-propos');
    expect(await seriousAxeViolations(page)).toEqual([]);
  });

  test('à propos : aucun défilement horizontal à 320 px', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 640 });
    await page.goto('/a-propos');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);
  });
});

test.describe('SEO — données de l\'API', () => {
  let reachable = false;

  test.beforeAll(async ({ request }) => {
    reachable = await apiReachable(request);
  });

  test.beforeEach(() => {
    test.skip(!reachable, 'API injoignable : tests de données ignorés');
  });

  test('accueil : lien canonique', async ({ page }) => {
    await page.setExtraHTTPHeaders(BYPASS_DATA_CACHE);
    await page.goto('/');
    // Next écrit la canonique de « / » sans barre finale : équivalente pour les moteurs.
    const href = await page.locator('link[rel="canonical"]').getAttribute('href');
    expect(href?.replace(/\/$/, '')).toBe(ORIGIN);
  });

  test('sitemap.xml : fiches publiées et collections non vides', async ({ page, request }) => {
    const res = await request.get('/sitemap.xml', { headers: BYPASS_DATA_CACHE });
    const locs = (await sitemapLocs(page, await res.text())) ?? [];
    const list = await request.get(`${API}/library/documents?tri=recent&per_page=1`, { timeout: 3000 });
    test.skip(!list.ok(), 'API indisponible pendant le test');
    const { data } = (await list.json()) as { data: { slug: string; type: string }[] };
    test.skip(data.length === 0, 'catalogue vide');
    expect(locs).toContain(`${ORIGIN}/documents/${encodeURIComponent(data[0].slug)}`);
    expect(locs).toContain(`${ORIGIN}/collections/${data[0].type}`);
  });
});
