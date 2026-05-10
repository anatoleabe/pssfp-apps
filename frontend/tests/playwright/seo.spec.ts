import { test, expect } from '@playwright/test';

/**
 * Tests SEO (PR P) — robots.txt, sitemap.xml, JSON-LD inline.
 */

test.describe('SEO — robots.txt', () => {
  test('robots.txt is reachable and references the sitemap', async ({ request }) => {
    const response = await request.get('/robots.txt');
    expect(response.status()).toBe(200);
    const body = await response.text();
    expect(body).toMatch(/user-agent:\s*\*/i);
    expect(body).toMatch(/sitemap:/i);
    expect(body).toMatch(/sitemap\.xml/i);
  });

  test('robots.txt allows /', async ({ request }) => {
    const response = await request.get('/robots.txt');
    const body = await response.text();
    expect(body).toMatch(/allow:\s*\//i);
  });
});

test.describe('SEO — sitemap.xml', () => {
  test('sitemap.xml is reachable and contains static routes', async ({ request }) => {
    const response = await request.get('/sitemap.xml');
    expect(response.status()).toBe(200);
    const body = await response.text();
    expect(body).toContain('<urlset');
    expect(body).toMatch(/<loc>https?:\/\/[^<]+<\/loc>/);
    // Au moins les routes statiques principales
    expect(body).toMatch(/\/a-propos/);
    expect(body).toMatch(/\/formations/);
    expect(body).toMatch(/\/actualites/);
    expect(body).toMatch(/\/contact/);
  });
});

test.describe('SEO — JSON-LD on home', () => {
  test('home renders EducationalOrganization JSON-LD', async ({ page }) => {
    await page.goto('/');
    const ldScripts = page.locator('script[type="application/ld+json"]');
    expect(await ldScripts.count()).toBeGreaterThan(0);
    const json = await ldScripts.first().textContent();
    expect(json).toBeTruthy();
    const parsed = JSON.parse(json ?? '{}');
    expect(parsed['@context']).toBe('https://schema.org');
    expect(parsed['@type']).toBe('EducationalOrganization');
    expect(parsed.name).toContain('PSSFP');
  });
});

test.describe('SEO — meta tags', () => {
  test('home has title and description meta', async ({ page }) => {
    await page.goto('/');
    await expect(page).toHaveTitle(/PSSFP/i);
    const description = await page.locator('meta[name="description"]').getAttribute('content');
    expect(description).toBeTruthy();
    expect(description?.length).toBeGreaterThan(20);
  });

  test('mentions-legales has its own title', async ({ page }) => {
    await page.goto('/mentions-legales');
    await expect(page).toHaveTitle(/Mentions légales/i);
  });
});

test.describe('SEO — opengraph metadata on home', () => {
  test('home declares og:site_name and og:locale', async ({ page }) => {
    await page.goto('/');
    const siteName = await page.locator('meta[property="og:site_name"]').getAttribute('content');
    const locale = await page.locator('meta[property="og:locale"]').getAttribute('content');
    expect(siteName).toBe('PSSFP');
    expect(locale).toBe('fr_FR');
  });
});
