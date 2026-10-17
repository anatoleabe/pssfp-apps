import { test, expect, type APIRequestContext, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
// Même base que l'app (playwright.config.ts charge .env.local comme `next dev`).
import { API_BASE_URL as API } from '../../lib/api/client';
import type { DocumentDetail } from '../../lib/api/types';
import { abstractParagraphs, doiUrl, documentJsonLd, excerpt, isBookType, schemaType, shortTitle } from '../../lib/document';
import { serializeJsonLd } from '../../lib/json-ld';
import { formatPrice } from '../../lib/format';
import { collectionHref, documentHref, downloadHref } from '../../lib/routes';

interface Summary { uuid: string; slug: string; type: string; title: string; access_mode: 'libre' | 'reserve' | 'payant'; price_xaf: number | null }
interface Detail extends Summary {
  abstract: string | null;
  mots_cles: { slug: string; label: string }[];
  access: { allowed: boolean; reason: string };
}
interface DetailResponse { data: Detail; related: Summary[] }

/** Réponse 429 : la limite de débit de l'API est atteinte (voir LIBRARY_RATE_LIMIT dans backend/.env). */
const THROTTLED = 'throttled';
const THROTTLED_MESSAGE = 'API limitée (429) : relever LIBRARY_RATE_LIMIT dans backend/.env ou attendre une minute';

interface Fixtures {
  /** Premier document de la liste par défaut, avec sa fiche complète (résumé, liés, décision d'accès). */
  first: DetailResponse;
  /** Premiers documents de chaque mode d'accès (résumés de la liste ; null si aucun). */
  byMode: Record<Summary['access_mode'], Summary | null>;
}

/**
 * Deux appels seulement (liste + fiche du premier) : ce `beforeAll` tourne une fois par worker.
 * Null si l'API est injoignable ou vide ; `THROTTLED` si elle répond 429 (jamais confondu avec « pas d'API »).
 */
async function loadFixtures(request: APIRequestContext): Promise<Fixtures | typeof THROTTLED | null> {
  try {
    const list = await request.get(`${API}/library/documents?per_page=50`, { timeout: 3000 });
    if (list.status() === 429) return THROTTLED;
    if (!list.ok()) return null;
    const docs = ((await list.json()) as { data: Summary[] }).data;
    if (docs.length === 0) return null;
    const res = await request.get(`${API}/library/documents/${encodeURIComponent(docs[0].slug)}`, { timeout: 3000 });
    if (res.status() === 429) return THROTTLED;
    if (!res.ok()) return null;
    const pick = (mode: Summary['access_mode']): Summary | null => docs.find((d) => d.access_mode === mode) ?? null;
    return { first: (await res.json()) as DetailResponse, byMode: { libre: pick('libre'), reserve: pick('reserve'), payant: pick('payant') } };
  } catch {
    return null;
  }
}

/** État de l'API (un seul appel léger) : joignable, injoignable ou limitée. */
async function apiStatus(request: APIRequestContext): Promise<'ok' | 'down' | typeof THROTTLED> {
  try {
    const res = await request.get(`${API}/library/stats`, { timeout: 3000 });
    if (res.status() === 429) return THROTTLED;
    return res.ok() ? 'ok' : 'down';
  } catch {
    return 'down';
  }
}

/** Contourne le cache de données de Next en dev : la page reflète l'état réel de l'API. */
const BYPASS_DATA_CACHE = { 'cache-control': 'no-cache' };
const VIEW_URL = /\/library\/documents\/[^/]+\/view$/;

/** Les consultations sont interceptées : les tests ne gonflent pas les compteurs de la base de dev. */
async function preparePage(page: Page): Promise<void> {
  await page.setExtraHTTPHeaders(BYPASS_DATA_CACHE);
  await page.route('**/library/documents/*/view', (route) => route.fulfill({ status: 204 }));
}

async function seriousAxeViolations(page: Page): Promise<string[]> {
  const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
  return results.violations
    .filter((v) => v.impact === 'critical' || v.impact === 'serious')
    .map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`);
}

const EXPECTED_REASONS: Record<Summary['access_mode'], string[]> = {
  libre: ['public', 'external'],
  reserve: ['reserved'],
  payant: ['paid'],
};

test.describe('Fiche — utilitaires', () => {
  test('excerpt : 160 caractères au plus, coupé sur un mot', () => {
    expect(excerpt('Un résumé court.', 160)).toBe('Un résumé court.');
    expect(excerpt('  plusieurs \n\n espaces  ', 160)).toBe('plusieurs espaces');
    const long = 'mot '.repeat(80);
    const cut = excerpt(long, 160);
    expect(cut.length).toBeLessThanOrEqual(160);
    expect(cut.endsWith('mot…')).toBe(true);
    expect(excerpt('x'.repeat(200), 160)).toHaveLength(160);
  });

  test('shortTitle : titre court pour le fil d\'Ariane', () => {
    expect(shortTitle('Titre bref')).toBe('Titre bref');
    const t = shortTitle('La décentralisation fiscale au Cameroun : bilan, perspectives et recommandations pour 2030');
    expect(t.length).toBeLessThanOrEqual(60);
    expect(t.endsWith('…')).toBe(true);
    expect(t).not.toMatch(/[\s:,;]…$/);
  });

  test('abstractParagraphs : paragraphes séparés par une ligne vide, retours simples conservés', () => {
    expect(abstractParagraphs(null)).toEqual([]);
    expect(abstractParagraphs('  ')).toEqual([]);
    expect(abstractParagraphs('Premier.\nSuite.\n\n\nSecond.\r\n\r\nTroisième.')).toEqual(['Premier.\nSuite.', 'Second.', 'Troisième.']);
    expect(abstractParagraphs('<b>texte</b>')).toEqual(['<b>texte</b>']);
  });

  test('doiUrl : lien doi.org encodé, préfixe toléré, sinon null', () => {
    expect(doiUrl('10.1000/xyz 123')).toBe('https://doi.org/10.1000/xyz%20123');
    expect(doiUrl('https://doi.org/10.1000/abc')).toBe('https://doi.org/10.1000/abc');
    expect(doiUrl('javascript:alert(1)')).toBeNull();
    expect(doiUrl(null)).toBeNull();
  });

  test('JSON-LD : un titre contenant </script> ne ferme pas la balise', () => {
    // Aucun document de la base de dev n'a ce titre : contrôle sur la sérialisation réelle de `JsonLd`.
    const title = 'Piège </script><script>alert(1)</script>';
    const doc = {
      uuid: 'u', slug: 'piege', type: 'article_scientifique', type_label: '', title, subtitle: null, year: 2020, language: 'fr',
      specialite: null, promotion: null, authors: ['A </script>'], mots_cles: [], cover_url: null, access_mode: 'libre', price_xaf: null,
      published_at: null, abstract: null, journal: null, doi: null, isbn: null, pages_count: null, file_size_bytes: null,
      external_url: null, views_count: 0, access: { allowed: true, reason: 'public' },
    } satisfies DocumentDetail;
    const html = serializeJsonLd(documentJsonLd(doc, { origin: 'https://exemple.org', publisherName: 'PSSFP', publisherUrl: 'https://pssfp.org/' }));
    expect(html).not.toContain('<');
    const parsed = JSON.parse(html) as { name: string; author: { name: string }[] };
    expect(parsed.name).toBe(title);
    expect(parsed.author[0].name).toBe('A </script>');
  });

  test('schemaType et isBookType selon le type de document', () => {
    expect(schemaType('memoire')).toBe('Book');
    expect(schemaType('these')).toBe('Book');
    expect(schemaType('ouvrage_numerique')).toBe('Book');
    expect(schemaType('article_scientifique')).toBe('ScholarlyArticle');
    expect(schemaType('texte_loi')).toBe('CreativeWork');
    expect(isBookType('cours')).toBe(false);
  });
});

test.describe('Fiche — toujours (avec ou sans API)', () => {
  let status: Awaited<ReturnType<typeof apiStatus>> = 'down';

  test.beforeAll(async ({ request }) => {
    status = await apiStatus(request);
  });

  test.beforeEach(async ({ page }) => {
    await preparePage(page);
  });

  test('document inexistant : 404 avec l\'API, frontière d\'erreur sans', async ({ page }) => {
    // Une API limitée rendrait la frontière d'erreur : le test passerait à tort.
    expect(status, THROTTLED_MESSAGE).not.toBe(THROTTLED);
    const res = await page.goto('/documents/inexistant-xyz');
    await expect(page.locator('h1')).toHaveCount(1);
    if (status === 'ok') {
      expect(res?.status()).toBe(404);
      await expect(page.getByRole('heading', { level: 1 })).toContainText('pas dans nos rayons');
    } else {
      await expect(page.getByTestId('page-error')).toBeVisible();
      await expect(page.getByTestId('page-error').getByRole('alert')).toBeVisible();
      await expect(page.getByRole('banner')).toBeVisible();
    }
  });
});

test.describe('Fiche — données de l\'API', () => {
  let first: DetailResponse | null = null;
  let libre: Summary | null = null;
  let payant: Summary | null = null;
  let reserve: Summary | null = null;

  let throttled = false;

  test.beforeAll(async ({ request }) => {
    const loaded = await loadFixtures(request);
    throttled = loaded === THROTTLED;
    const fixtures = loaded === THROTTLED ? null : loaded;
    first = fixtures?.first ?? null;
    libre = fixtures?.byMode.libre ?? null;
    payant = fixtures?.byMode.payant ?? null;
    reserve = fixtures?.byMode.reserve ?? null;
  });

  test.beforeEach(async ({ page }) => {
    expect(throttled, THROTTLED_MESSAGE).toBe(false);
    test.skip(first === null, 'API injoignable : tests de données ignorés');
    await preparePage(page);
  });

  // Un seul chargement pour la fiche de référence (l'API limite à 120 requêtes par minute et par IP).
  test('fiche du premier document : en-tête, accès, consultation, JSON-LD, métadonnées, contenu', async ({ page }) => {
    const { data: doc, related } = first!;
    const beacon = page.waitForRequest((req) => VIEW_URL.test(req.url()) && req.method() === 'POST');
    const res = await page.goto(documentHref(doc.slug));

    await test.step('h1 = titre de l\'API, fil d\'Ariane vers la collection', async () => {
      expect(res?.status()).toBe(200);
      await expect(page.getByTestId('page-error')).toHaveCount(0);
      await expect(page.locator('h1')).toHaveCount(1);
      await expect(page.getByRole('heading', { level: 1 })).toHaveText(doc.title);
      const crumbs = page.getByRole('navigation', { name: 'Fil d\'Ariane' });
      await expect(crumbs.getByRole('link', { name: 'Accueil' })).toHaveAttribute('href', '/');
      await expect(crumbs.getByRole('link').nth(1)).toHaveAttribute('href', collectionHref(doc.type as never));
      await expect(crumbs.getByTestId('breadcrumb-current')).toHaveText(shortTitle(doc.title));
    });

    await test.step('action d\'accès cohérente avec le mode d\'accès', async () => {
      const action = page.getByTestId('access-action');
      await expect(action).toHaveCount(1);
      const reason = await action.getAttribute('data-reason');
      expect(EXPECTED_REASONS[doc.access_mode]).toContain(reason);
      expect(reason).toBe(doc.access.reason);
    });

    await test.step('une consultation est signalée au chargement', async () => {
      const req = await beacon;
      expect(req.url()).toContain(`/library/documents/${doc.uuid}/view`);
    });

    await test.step('JSON-LD valide : nom, auteurs Person, accès gratuit', async () => {
      const raw = await page.locator('script[type="application/ld+json"]').first().textContent();
      const ld = JSON.parse(raw ?? '') as Record<string, unknown>;
      expect(ld['@context']).toBe('https://schema.org');
      expect(ld['@type']).toBe(schemaType(doc.type as never));
      expect(ld.name).toBe(doc.title);
      expect(ld.isAccessibleForFree).toBe(doc.access_mode === 'libre');
      expect(String(ld.url)).toMatch(new RegExp(`${documentHref(doc.slug)}$`));
      for (const author of (ld.author as { '@type': string }[] | undefined) ?? []) expect(author['@type']).toBe('Person');
      expect((ld.publisher as { '@type': string })['@type']).toBe('Organization');
    });

    await test.step('métadonnées : titre, canonique, Open Graph, description', async () => {
      await expect(page).toHaveTitle(new RegExp(`^${doc.title.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`));
      await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', new RegExp(`${documentHref(doc.slug)}$`));
      await expect(page.locator('meta[property="og:type"]')).toHaveAttribute('content', isBookType(doc.type as never) ? 'book' : 'article');
      const description = await page.locator('meta[name="description"]').getAttribute('content');
      expect(description?.length ?? 0).toBeGreaterThan(0);
      expect(description!.length).toBeLessThanOrEqual(160);
      if (doc.abstract) expect(description).toBe(excerpt(doc.abstract, 160));
    });

    await test.step('résumé, mots-clés vers la collection filtrée, citation, documents liés', async () => {
      if (doc.abstract) {
        const abstract = page.getByTestId('document-abstract');
        await expect(abstract.getByRole('heading', { level: 2, name: 'Résumé' })).toBeVisible();
        await expect(abstract.locator('p')).toHaveCount(abstractParagraphs(doc.abstract).length);
      }
      const keywords = page.getByTestId('document-keywords');
      if (doc.mots_cles.length === 0) await expect(keywords).toHaveCount(0);
      for (const mc of doc.mots_cles) {
        await expect(keywords.getByRole('link', { name: mc.label, exact: true })).toHaveAttribute('href', collectionHref(doc.type as never, { mot_cle: mc.slug }));
      }
      await expect(page.getByTestId('citation-box')).toBeVisible();
      const relatedSection = page.getByTestId('document-related');
      if (related.length === 0) {
        await expect(relatedSection).toHaveCount(0);
      } else {
        await expect(relatedSection.getByRole('heading', { level: 2 })).toBeVisible();
        await expect(relatedSection.locator('article')).toHaveCount(Math.min(4, related.length));
      }
    });

    await test.step('axe : aucune violation critique ou grave', async () => {
      expect(await seriousAxeViolations(page)).toEqual([]);
    });
  });

  test('document libre : lien de téléchargement par l\'API', async ({ page }) => {
    test.skip(libre === null, 'aucun document libre');
    const doc = libre!;
    await page.goto(documentHref(doc.slug));
    const action = page.getByTestId('access-action');
    const reason = await action.getAttribute('data-reason');
    expect(EXPECTED_REASONS.libre).toContain(reason);
    // Lien externe : URL de l'éditeur (http(s)) ou redirection de l'API ; fichier : téléchargement par l'API.
    if (reason === 'public') await expect(action.getByRole('link')).toHaveAttribute('href', downloadHref(doc.uuid));
    else await expect(action.getByRole('link')).toHaveAttribute('href', /^https?:\/\//);
  });

  test('document payant : pas de lien de téléchargement, prix affiché, axe', async ({ page }) => {
    test.skip(payant === null, 'aucun document payant');
    const doc = payant!;
    await page.goto(documentHref(doc.slug));
    const action = page.getByTestId('access-action');
    await expect(action).toHaveAttribute('data-reason', 'paid');
    await expect(action.getByRole('link')).toHaveCount(0);
    await expect(page.getByRole('main').locator('a[href*="/download"]')).toHaveCount(0);
    if (doc.price_xaf !== null) await expect(action).toContainText(`${formatPrice(doc.price_xaf)} FCFA`);
    expect(await seriousAxeViolations(page)).toEqual([]);
  });

  test('document réservé : aucun lien, mention réservée', async ({ page }) => {
    test.skip(reserve === null, 'aucun document réservé');
    const doc = reserve!;
    await page.goto(documentHref(doc.slug));
    const action = page.getByTestId('access-action');
    await expect(action).toHaveAttribute('data-reason', 'reserved');
    await expect(action.getByRole('link')).toHaveCount(0);
    await expect(page.getByRole('main').locator('a[href*="/download"]')).toHaveCount(0);
  });

  test('≥ lg : la couverture reste collante sous l\'en-tête au défilement', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(documentHref(first!.data.slug));
    const column = page.getByTestId('document-cover-column');
    const stickyTop = await column.evaluate((el) => parseFloat(getComputedStyle(el).top));
    const { coverTop, coverHeight, articleHeight } = await column.evaluate((el) => {
      const article = el.parentElement!.querySelector('article')!;
      return { coverTop: el.getBoundingClientRect().top + window.scrollY, coverHeight: el.getBoundingClientRect().height, articleHeight: article.getBoundingClientRect().height };
    });
    const travel = 150;
    test.skip(articleHeight < coverHeight + travel, 'notice trop courte pour faire défiler la couverture');
    // Sans `sticky`, la couverture remonterait à stickyTop - travel.
    await page.evaluate((y) => window.scrollTo(0, y), coverTop - stickyTop + travel);
    await expect.poll(() => column.evaluate((el) => el.getBoundingClientRect().top)).toBeCloseTo(stickyTop, 0);
  });

  test('aucun défilement horizontal à 320 px', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 640 });
    for (const slug of [first!.data.slug, payant?.slug].filter((s): s is string => !!s)) {
      await page.goto(documentHref(slug));
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      expect(overflow).toBeLessThanOrEqual(0);
    }
  });

  // Captures d'écran pour la revue visuelle : uniquement si SCREENSHOT_DIR est défini (jamais en CI).
  for (const width of [320, 1440]) {
    for (const kind of ['libre', 'payant'] as const) {
      test(`@visuel capture ${kind} ${width} px`, async ({ page }) => {
        test.skip(!process.env.SCREENSHOT_DIR || !!process.env.CI, 'captures manuelles uniquement');
        const doc = kind === 'libre' ? libre : payant;
        test.skip(doc === null, `aucun document ${kind}`);
        await page.setViewportSize({ width, height: 900 });
        await page.goto(documentHref(doc!.slug));
        await page.waitForLoadState('networkidle');
        await page.screenshot({ path: `${process.env.SCREENSHOT_DIR}/fiche-${kind}-${width}.png`, fullPage: true });
      });
    }
  }
});
