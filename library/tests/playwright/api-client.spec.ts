import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { test, expect } from '@playwright/test';
import { apiGet } from '../../lib/api/client';
import { getStats, searchDocuments } from '../../lib/api/library';
import sitemap from '../../app/sitemap';

type FetchFn = typeof fetch;
const realFetch: FetchFn = globalThis.fetch;

function stubFetch(impl: (input: RequestInfo | URL, init?: RequestInit) => Promise<Response>): void {
  globalThis.fetch = impl as FetchFn;
}

/** fetch factice qui ne répond jamais et rejette comme le vrai fetch quand son signal est annulé. */
const hangingFetch = (_: RequestInfo | URL, init?: RequestInit): Promise<Response> =>
  new Promise((_resolve, reject) => {
    const signal = init?.signal;
    if (signal?.aborted) return reject(new DOMException('aborted', 'AbortError'));
    signal?.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')));
  });

const json = (body: unknown, status = 200): Response =>
  new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

test.describe('apiGet', () => {
  test.afterEach(() => {
    globalThis.fetch = realFetch;
  });

  test('API injoignable : {ok:false, status:0}, sans exception', async () => {
    stubFetch(() => Promise.reject(new TypeError('fetch failed')));
    const r = await apiGet('/library/stats');
    expect(r).toEqual({ ok: false, error: { message: 'fetch failed', status: 0 } });
  });

  test('vraie connexion refusée (127.0.0.1:9) : {ok:false, status:0}', async () => {
    stubFetch((_input, init) => realFetch('http://127.0.0.1:9/v1/library/stats', init));
    const r = await apiGet('/library/stats', { timeoutMs: 2000 });
    expect(r.ok).toBe(false);
    if (!r.ok) expect(r.error.status).toBe(0);
  });

  test('délai dépassé : {ok:false, status:0}, sans exception', async () => {
    stubFetch(hangingFetch);
    const r = await apiGet('/library/stats', { timeoutMs: 30 });
    expect(r.ok).toBe(false);
    if (!r.ok) expect(r.error.status).toBe(0);
  });

  test('statut HTTP en erreur : status transmis', async () => {
    stubFetch(async () => json({ message: 'introuvable' }, 404));
    expect(await apiGet('/library/documents/x')).toEqual({ ok: false, error: { message: 'API 404', status: 404 } });
  });

  test('annulation par l\'appelant : l\'erreur est relancée', async () => {
    stubFetch(hangingFetch);
    const controller = new AbortController();
    const pending = apiGet('/library/search?q=tva', { signal: controller.signal, timeoutMs: 5000 });
    controller.abort();
    await expect(pending).rejects.toThrow();
  });

  test('signal déjà annulé : l\'erreur est relancée', async () => {
    stubFetch(hangingFetch);
    const controller = new AbortController();
    controller.abort();
    await expect(apiGet('/x', { signal: controller.signal })).rejects.toThrow();
  });

  test('un signal appelant non annulé ne gêne pas une réponse correcte', async () => {
    stubFetch(async () => json({ data: { total: 1 } }));
    const controller = new AbortController();
    expect(await apiGet('/library/stats', { signal: controller.signal })).toEqual({ ok: true, data: { data: { total: 1 } } });
  });
});

test.describe('normalisation des objets vides PHP', () => {
  test.afterEach(() => {
    globalThis.fetch = realFetch;
  });

  test('getStats : by_type [] devient {}', async () => {
    stubFetch(async () => json({ data: { total: 0, by_type: [], paid_count: 0 } }));
    const r = await getStats();
    expect(r.ok && r.data.data.by_type).toEqual({});
  });

  test('searchDocuments : facettes [] deviennent {}', async () => {
    stubFetch(async () => json({ data: [], highlights: [], meta: { total: 0, page: 1, per_page: 20, last_page: 1, facets: { type: [], access_mode: [] } } }));
    const r = await searchDocuments('tva', {}, 1);
    expect(r.ok).toBe(true);
    if (r.ok) {
      expect(r.data.meta.facets.type).toEqual({});
      expect(r.data.meta.facets.access_mode).toEqual({});
      expect(r.data.highlights).toEqual({});
    }
  });
});

test.describe('next.config.js : NEXT_PUBLIC_API_URL en production', () => {
  /** Charge la configuration dans un processus neuf, sans fichiers .env (environnement maîtrisé). */
  function loadConfig(env: Record<string, string>): { status: number | null; stderr: string } {
    const { PATH = '', HOME = '' } = process.env;
    const r = spawnSync(process.execPath, ['-e', "require('./next.config.js')"], {
      cwd: path.resolve(__dirname, '../..'),
      env: { PATH, HOME, ...env },
      encoding: 'utf8',
    });
    return { status: r.status, stderr: r.stderr };
  }

  test('build de production sans la variable (hors CI) : erreur explicite', () => {
    const r = loadConfig({ NODE_ENV: 'production' });
    expect(r.status).not.toBe(0);
    expect(r.stderr).toContain('NEXT_PUBLIC_API_URL');
  });

  test('variable présente, ou build de CI, ou développement : configuration chargée', () => {
    expect(loadConfig({ NODE_ENV: 'production', NEXT_PUBLIC_API_URL: 'https://api.pssfp.org/v1' }).status).toBe(0);
    expect(loadConfig({ NODE_ENV: 'production', CI: 'true' }).status).toBe(0);
    expect(loadConfig({ NODE_ENV: 'development' }).status).toBe(0);
  });
});

test.describe('sitemap : cache d\'une heure et journalisation', () => {
  type Call = { url: string; revalidate: unknown };
  let calls: Call[] = [];
  let warnings: string[] = [];
  const realWarn = console.warn;

  const doc = (i: number): Record<string, unknown> => ({ slug: `doc-${i}`, published_at: '2026-01-01T00:00:00Z' });

  /** fetch factice : statistiques et listes paginées (`lastPage` pages pleines de 50 fiches). */
  function stubApi(lastPage: number): void {
    stubFetch(async (input, init) => {
      const url = String(input);
      calls.push({ url, revalidate: (init as { next?: { revalidate?: unknown } } | undefined)?.next?.revalidate });
      if (url.includes('/library/stats')) return json({ data: { total: 2, by_type: { memoire: 2 }, paid_count: 0 } });
      if (url.includes('/library/mots-cles')) {
        return json({ data: [
          { slug: 'themes', label: 'Thèmes', parent_slug: null, documents_count: 2 },
          { slug: 'fiscalite', label: 'Fiscalité', parent_slug: 'themes', documents_count: 2 },
          { slug: 'vide', label: 'Vide', parent_slug: 'themes', documents_count: 0 },
        ] });
      }
      const page = Number(new URL(url).searchParams.get('page') ?? 1);
      return json({ data: Array.from({ length: 50 }, (_, i) => doc((page - 1) * 50 + i)), meta: { current_page: page, last_page: lastPage, total: lastPage * 50 } });
    });
  }

  test.beforeEach(() => {
    calls = [];
    warnings = [];
    console.warn = (...args: unknown[]): void => {
      warnings.push(args.map(String).join(' '));
    };
  });

  test.afterEach(() => {
    globalThis.fetch = realFetch;
    console.warn = realWarn;
  });

  test('tous les appels de la sitemap demandent revalidate: 3600 (la route se régénère à l\'heure)', async () => {
    stubApi(2);
    const entries = await sitemap();
    expect(calls.length).toBeGreaterThanOrEqual(3);
    expect(calls.map((c) => c.revalidate)).toEqual(calls.map(() => 3600));
    expect(entries.some((e) => e.url.endsWith('/collections/memoire'))).toBe(true);
    // Thèmes comptant des documents publiés (racine comprise) ; un thème vide n'y figure pas.
    expect(entries.filter((e) => e.url.includes('/themes/')).map((e) => new URL(e.url).pathname)).toEqual(['/themes/themes', '/themes/fiscalite']);
    expect(entries.filter((e) => e.url.includes('/documents/'))).toHaveLength(100);
    expect(warnings).toEqual([]);
  });

  test('API en échec : sitemap réduite aux pages fixes, avertissement journalisé', async () => {
    stubFetch(async () => json({ message: 'panne' }, 503));
    const entries = await sitemap();
    expect(entries.map((e) => new URL(e.url).pathname)).toEqual(['/', '/a-propos', '/themes']);
    expect(warnings.join('\n')).toMatch(/sitemap/i);
    expect(warnings.length).toBeGreaterThanOrEqual(3);
  });

  test('liste tronquée au plafond : avertissement journalisé', async () => {
    stubApi(500);
    const entries = await sitemap();
    expect(entries.filter((e) => e.url.includes('/documents/'))).toHaveLength(5000);
    expect(warnings.join('\n')).toMatch(/tronqu/i);
  });
});
