# Bibliothèque V1 — Plan 2 : site public (`/library`)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construire la bibliothèque publique moderne sur `bibliotheque.pssfp.org` : accueil combinant les maquettes A + B + C, collections filtrables, recherche, fiche document, redirection des anciennes URL Joomla, SEO — sur l'API livrée par le Plan 1.

**Architecture:** App Next.js 14 App Router existante (`library/`, port 6002), composants locaux (packages/ui ne fournit ni en-tête ni pied de page), données via `NEXT_PUBLIC_API_URL`. Pages de catalogue rendues côté serveur avec ISR 5 min ; **recherche exécutée dans le navigateur** (débit par IP et journaux de recherche corrects) ; **consultations comptées par un appel du navigateur** (pas par le rendu ISR) ; middleware pour les 301 des anciennes URL.

**Tech Stack:** Next.js 14.2, TypeScript strict (`noUncheckedIndexedAccess`), Tailwind + preset `@pssfp/ui`, next-intl 3 (FR), lucide-react, Playwright + @axe-core/playwright ; backend Laravel 11 / Pest pour la tâche 1.

**Spec :** `docs/specs/module-3-bibliotheque-v1-2026-10.md` §6 (site public) et §8. **Maquettes validées le 2026-10-17 :** `docs/specs/assets/bibliotheque-maquettes-2026-10-17.html` + `maquette-A.png`, `maquette-B.png`, `maquette-C.png` (décision : combiner les trois).

## Global Constraints

- Branche `feat/m3-bibliotheque-front` (empilée sur `feat/m3-bibliotheque-v1`). Commits Conventional Commits en français sans accents dans le sujet, terminés par un trailer `Co-Authored-By: Claude <Modèle> 5.5 <noreply@anthropic.com>` nommant le modèle qui a écrit le code. `git commit` dans un appel Bash isolé ; jamais `--no-verify`.
- **Aucun texte en dur dans le JSX** : tout texte visible passe par `next-intl` (`messages/fr.json`, `getTranslations` côté serveur, `useTranslations` côté client). Attributs `aria-label`, `alt`, `title` compris.
- **WCAG 2.1 AA** : contraste ≥ 4.5:1, navigation clavier complète, lien d'évitement `#main`, `focus-visible` visible, `alt` sur toute image (vide si décorative), `prefers-reduced-motion` respecté. Contrôle axe sans violation `critical` ni `serious` sur chaque page.
- **Lighthouse ≥ 85** sur Performance / Accessibilité / SEO / Best Practices (accueil, recherche, fiche).
- Mobile d'abord : aucune barre de défilement horizontale à 320 px ; points de contrôle 320, 768, 1024, 1440.
- Charte ADR-0008 via le preset Tailwind : `pssfp-prune` (#4A2E67, `-dark` #3A2452, `-light` #5C3A7E), `pssfp-lavande`, `pssfp-bleu-petrole` (#0F3A4A), `pssfp-or` (#D4AF6A), `pssfp-ivoire` (#FAF7F2), `pssfp-graphite` ; polices `font-heading` (Cormorant Garamond), `font-body` (Source Sans 3), `font-ui` (DM Sans). Pas d'icône `Sparkles` en décoration.
- Domaine canonique `https://bibliotheque.pssfp.org` (variable `NEXT_PUBLIC_SITE_URL`), site principal `NEXT_PUBLIC_MAIN_SITE_URL`, API `NEXT_PUBLIC_API_URL` (défaut `http://localhost:8000/v1`).
- Identifiants publics : `slug` (pages) et `uuid` (téléchargement, consultation). Jamais d'`id`.
- Les tests Playwright doivent passer **avec ou sans backend** (la CI ne démarre pas l'API) : chaque page a un état d'erreur `role="alert"`, un état vide `role="status"` et des `data-testid` stables ; les tests qui exigent des données se marquent `test.skip()` si l'API est injoignable.
- Ports : dev library 6002, backend 8000 ; ne jamais utiliser 3001 ni 6000.
- Lancer depuis `library/` : `pnpm lint`, `pnpm typecheck`, `pnpm build`, `pnpm test`. Backend : `cd backend && vendor/bin/pest <chemin>`, `vendor/bin/pint --dirty`.

---

## Contrat de données (API Plan 1, rappel)

```text
GET /v1/library/stats            → {data:{total, by_type:{<type>:n} | [], paid_count}}
GET /v1/library/mots-cles        → {data:[{slug, label, parent_slug|null}]}
GET /v1/library/redirects?path=  → {data:{to_path, status_code}} | 404
GET /v1/library/documents?type&specialite&promotion&annee_min&annee_max&langue&acces&mot_cle&tri&per_page&page
                                 → {data:[DocumentSummary], links:{…}, meta:{current_page,last_page,per_page,total,from,to,…}}
GET /v1/library/documents/{slug} → {data:DocumentDetail, related:[DocumentSummary]} | 404
GET /v1/library/search?q&…filtres&page → {data:[DocumentSummary], highlights:{<uuid>:"titre échappé avec <mark>"}, meta:{total,page,per_page,last_page,facets:{type:{…},access_mode:{…}}}}
GET /v1/library/documents/{uuid}/download → 302 (fichier signé 30 min ou lien externe) | 403 {reason} | 404
POST /v1/library/documents/{uuid}/view    → 204   (ajouté par la tâche 1)
```

Types : `memoire, these, ouvrage_numerique, texte_loi, article_scientifique, cours, acte_conference, rapport_stage, bulletin, ressource_pedagogique`. Accès : `libre | reserve | payant`. Tris : `recent | ancien | titre | populaire`.

## File Structure

| Fichier | Responsabilité |
|---|---|
| `backend/app/Http/Controllers/Api/Library/ViewController.php` | Compte une consultation (dédoublonnée) |
| `backend/database/seeders/LibraryDemoSeeder.php` | Jeu de données **fictif** pour le dev et les tests E2E locaux |
| `library/lib/api/types.ts`, `library/lib/api/client.ts`, `library/lib/api/library.ts` | Types et appels API (serveur et navigateur) |
| `library/lib/highlight.ts` | Assainissement des titres surlignés (seul `<mark>` autorisé) |
| `library/lib/routes.ts` | Construction des URL (collections, recherche, fiche, téléchargement) |
| `library/components/layout/*` | En-tête, tiroir mobile, pied de page, fil d'Ariane |
| `library/components/document/*` | Carte, couverture, badge d'accès, bouton d'accès, citation, balise de consultation |
| `library/components/catalogue/*` | Filtres, tri, pagination, états vide/erreur |
| `library/components/home/*` | Héros, mosaïque, nouveautés, plus lus |
| `library/app/**` | Pages : accueil, collections, recherche, fiche, à propos, sitemap, robots, 404 |
| `library/middleware.ts` | 301 des anciennes URL Joomla |
| `library/tests/playwright/*.spec.ts` | E2E + axe |

---

### Task 1: Ajustements backend (consultations, couvertures, données de démonstration)

**Files:**
- Create: `backend/app/Http/Controllers/Api/Library/ViewController.php`
- Modify: `backend/app/Http/Controllers/Api/Library/DocumentController.php` (retirer l'incrément de `show`)
- Modify: `backend/routes/api.php` (route `documents.view`)
- Modify: `backend/config/filesystems.php` (disque `minio_media` : clé `url`)
- Create: `backend/database/seeders/LibraryDemoSeeder.php`
- Modify: `.env.example` (racine) et `library/.env.example`
- Test: `backend/tests/Feature/Library/ViewCounterTest.php`, ajustement de `CatalogueApiTest.php`

**Interfaces:**
- Produces: `POST /v1/library/documents/{uuid}/view` (route `library.documents.view`) → 204 ; une seule consultation comptée par couple (IP, document) et par heure ; 404 si inconnu ou non publié ; throttle `20,1,library-view`. `GET /documents/{slug}` ne modifie plus `views_count`. `cover_url` utilise `MINIO_MEDIA_PUBLIC_URL` quand elle est définie.

- [ ] **Step 1: Tests qui échouent**

`backend/tests/Feature/Library/ViewCounterTest.php` :

```php
<?php

declare(strict_types=1);

use App\Models\Document;
use Illuminate\Support\Facades\Cache;

uses()->group('module-3', 'library');

beforeEach(fn () => Cache::flush());

it('compte une consultation et renvoie 204', function (): void {
    $doc = Document::factory()->published()->create();

    $this->postJson("/v1/library/documents/{$doc->uuid}/view")->assertNoContent();

    expect($doc->fresh()->views_count)->toBe(1);
});

it('ne compte qu\'une consultation par IP et par heure', function (): void {
    $doc = Document::factory()->published()->create();

    $this->postJson("/v1/library/documents/{$doc->uuid}/view")->assertNoContent();
    $this->postJson("/v1/library/documents/{$doc->uuid}/view")->assertNoContent();
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
        ->postJson("/v1/library/documents/{$doc->uuid}/view")->assertNoContent();

    expect($doc->fresh()->views_count)->toBe(2);
});

it('renvoie 404 pour un document inconnu, non publié ou un uuid invalide', function (): void {
    $brouillon = Document::factory()->create();

    $this->postJson("/v1/library/documents/{$brouillon->uuid}/view")->assertNotFound();
    $this->postJson('/v1/library/documents/7f1c0e9a-0000-4000-8000-000000000000/view')->assertNotFound();
    $this->postJson('/v1/library/documents/pas-un-uuid/view')->assertNotFound();
});

it('n\'incrémente plus les consultations à la lecture de la fiche', function (): void {
    $doc = Document::factory()->published()->create(['slug' => 'fiche-lue']);

    $this->getJson('/v1/library/documents/fiche-lue')->assertOk();

    expect($doc->fresh()->views_count)->toBe(0);
});
```

Dans `CatalogueApiTest.php`, remplacer l'assertion `expect($doc->fresh()->views_count)->toBe(1);` du test de fiche par `toBe(0)` et renommer le test « affiche une fiche avec la décision d'accès » (la consultation n'y est plus comptée).

- [ ] **Step 2: Vérifier l'échec** — `cd backend && vendor/bin/pest tests/Feature/Library/ViewCounterTest.php` → FAIL (route absente).

- [ ] **Step 3: Implémenter**

`backend/app/Http/Controllers/Api/Library/ViewController.php` :

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Library;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Consultation d'une fiche, signalée par le navigateur (spec V1 §6).
 *
 * Les fiches sont servies en ISR : compter au rendu compterait les
 * régénérations, pas les lecteurs. Une consultation par IP et par heure.
 */
final class ViewController extends Controller
{
    private const DEDUP_MINUTES = 60;

    public function __invoke(Request $request, string $uuid): Response
    {
        $document = Str::isUuid($uuid) ? Document::query()->published()->where('uuid', $uuid)->first() : null;
        abort_if($document === null, Response::HTTP_NOT_FOUND);

        $key = 'library:view:'.$document->id.':'.sha1((string) $request->ip());
        if (Cache::add($key, true, now()->addMinutes(self::DEDUP_MINUTES))) {
            DB::table('documents')->where('id', $document->id)->increment('views_count');
        }

        return response()->noContent();
    }
}
```

Dans `DocumentController::show`, supprimer les deux lignes d'incrément (`DB::table('documents')…increment('views_count')` et `$document->views_count++`) et l'import `DB` s'il devient inutile ; ajuster la docblock.

Dans `backend/routes/api.php`, groupe `library`, avant `/documents/{slug}` :

```php
    Route::post('/documents/{uuid}/view', ViewController::class)
        ->middleware('throttle:20,1,library-view')
        ->name('documents.view');
```

`backend/config/filesystems.php`, disque `minio_media`, ajouter :

```php
            // URL publique des médias (prod : https://media.pssfp.org/pssfp-media).
            // Absente en dev : Laravel retombe sur l'endpoint MinIO.
            'url' => env('MINIO_MEDIA_PUBLIC_URL'),
```

`.env.example` (racine), près des variables MinIO : `# MINIO_MEDIA_PUBLIC_URL=https://media.pssfp.org/pssfp-media`.

- [ ] **Step 4: Données de démonstration fictives**

`backend/database/seeders/LibraryDemoSeeder.php` — **jamais en prod** (refuse de s'exécuter si `app()->isProduction()`), données entièrement fictives (règle projet n°8) :

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Document;
use App\Models\LibraryRedirect;
use App\Models\MotCle;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Bibliothèque de démonstration (dev et E2E locaux) — données fictives.
 * `php artisan db:seed --class=LibraryDemoSeeder`
 */
final class LibraryDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('LibraryDemoSeeder est interdit en production.');
        }

        $motsCles = collect(['fiscalite' => 'Fiscalité', 'marches-publics' => 'Marchés publics', 'decentralisation' => 'Décentralisation', 'audit' => 'Audit et contrôle', 'budget' => 'Budget de l\'État'])
            ->map(fn (string $label, string $slug) => MotCle::query()->firstOrCreate(['slug' => $slug], ['label' => ['fr' => $label]]));

        $types = ['memoire' => 12, 'these' => 4, 'texte_loi' => 6, 'ouvrage_numerique' => 5, 'cours' => 3, 'article_scientifique' => 4];
        foreach ($types as $type => $count) {
            Document::factory()->published()->count($count)->create(['type' => $type, 'promotion' => $type === 'memoire' ? fake()->numberBetween(1, 13) : null])
                ->each(function (Document $doc) use ($motsCles, $type): void {
                    $doc->authors()->create(['author_name' => fake()->name(), 'order' => 1]);
                    $doc->motsCles()->attach($motsCles->random(2)->pluck('id'));
                    $doc->update(['views_count' => fake()->numberBetween(0, 900)]);
                    if ($type === 'cours') {
                        $doc->update(['access_level' => 'auditor']);
                    }
                });
        }

        Document::factory()->published()->paid(2500)->create(['type' => 'ouvrage_numerique', 'title' => ['fr' => 'Manuel de comptabilité publique (démonstration)']]);
        Document::factory()->published()->create(['type' => 'memoire', 'slug' => 'decentralisation-fiscale-demo', 'title' => ['fr' => 'La décentralisation fiscale : bilan et perspectives (démonstration)']]);

        // Ancienne URL Joomla de démonstration (tests de redirection du Plan 2, tâche 8).
        LibraryRedirect::query()->firstOrCreate(['from_path' => '/index.php/textes-et-lois'], ['to_path' => '/collections/texte_loi']);
    }
}
```

(Les fabriques ne posent pas de `file_path` : pas de traitement PDF déclenché. Adapter si `paid()`/`published()` exigent d'autres champs depuis les correctifs du Plan 1.)

`library/.env.example` : remplacer `NEXT_PUBLIC_MAIN_SITE_URL=http://localhost:6001` si besoin et ajouter `NEXT_PUBLIC_SITE_URL=http://localhost:6002`.

- [ ] **Step 5: Vérifier** — `cd backend && vendor/bin/pest tests/Feature/Library` → PASS ; `php artisan db:seed --class=LibraryDemoSeeder` sur la base de dev (après `php artisan migrate`) → ~36 documents ; puis `curl -s localhost:8000/v1/library/stats` (avec `php artisan serve --port=8000`) montre les compteurs.

- [ ] **Step 6: Commit** — `feat(biblio): consultations comptees par le navigateur, URL publique des couvertures, donnees de demonstration`.

---

### Task 2: Socle de l'app (configuration, client API, mise en page)

**Files:**
- Modify: `library/next.config.js`, `library/package.json` (ajout `sharp` ^0.33.5, `@axe-core/playwright` en dev s'il manque), `library/app/layout.tsx`, `library/app/globals.css`, `library/messages/fr.json`, `library/app/not-found.tsx`
- Create: `library/lib/api/types.ts`, `library/lib/api/client.ts`, `library/lib/api/library.ts`, `library/lib/routes.ts`, `library/lib/highlight.ts`
- Create: `library/components/layout/SiteHeader.tsx`, `MobileDrawer.tsx`, `SiteFooter.tsx`, `Breadcrumbs.tsx`
- Delete: `library/components/SearchBarPlaceholder/`
- Test: `library/tests/playwright/layout.spec.ts`, `library/tests/playwright/highlight.spec.ts`

**Interfaces:**
- Produces (utilisés par toutes les tâches suivantes) :
  - `types.ts` : `DocumentType`, `AccessMode`, `SortKey`, `DocumentSummary`, `DocumentDetail`, `AccessDecision`, `Paginated<T>`, `LibraryStats`, `MotCle`, `SearchResponse`, `CatalogueFilters`, `ApiResult<T>`.
  - `library.ts` (serveur, ISR 300 par défaut) : `getStats()`, `listDocuments(filters, page)`, `getDocument(slug)`, `getMotsCles()`, `resolveLegacyPath(path)` ; (navigateur) `searchDocuments(q, filters, page, signal)`, `recordView(uuid)`.
  - `routes.ts` : `collectionHref(type, params?)`, `searchHref(q, params?)`, `documentHref(slug)`, `downloadHref(uuid)`, `filtersToSearchParams(filters)`, `searchParamsToFilters(sp)`.
  - `highlight.ts` : `sanitizeHighlight(html: string): string`.
  - Composants : `<SiteHeader/>`, `<SiteFooter/>`, `<Breadcrumbs items={{href?, label}[]}/>`.

- [ ] **Step 1: Tests qui échouent**

`library/tests/playwright/layout.spec.ts` :

```ts
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test.describe('Socle', () => {
  test('lien d\'évitement, en-tête, pied de page et langue', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
    await page.keyboard.press('Tab');
    const skip = page.getByRole('link', { name: /aller au contenu/i });
    await expect(skip).toBeFocused();
    await expect(page.getByRole('banner')).toBeVisible();
    await expect(page.getByRole('contentinfo')).toBeVisible();
    await expect(page.getByRole('link', { name: /pssfp\.org|site du pssfp/i }).first()).toBeVisible();
  });

  test('menu mobile accessible au clavier', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 740 });
    await page.goto('/');
    const burger = page.getByRole('button', { name: /menu/i });
    await expect(burger).toHaveAttribute('aria-expanded', 'false');
    await burger.click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toBeHidden();
    await expect(burger).toBeFocused();
  });

  test('aucun défilement horizontal à 320 px', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 640 });
    await page.goto('/');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);
  });

  test('404 en français, sans violation axe grave', async ({ page }) => {
    const res = await page.goto('/une-page-qui-n-existe-pas');
    expect(res?.status()).toBe(404);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    expect(axe.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious')).toEqual([]);
  });
});
```

`library/tests/playwright/highlight.spec.ts` (test de fonction pure exécuté par Playwright, sans navigateur) :

```ts
import { test, expect } from '@playwright/test';
import { sanitizeHighlight } from '../../lib/highlight';

test('ne garde que les balises <mark>', () => {
  expect(sanitizeHighlight('La <mark>décentralisation</mark> fiscale')).toBe('La <mark>décentralisation</mark> fiscale');
  expect(sanitizeHighlight('<img src=x onerror=alert(1)><mark>a</mark>')).toBe('&lt;img src=x onerror=alert(1)&gt;<mark>a</mark>');
  expect(sanitizeHighlight('&lt;script&gt; <mark class="x">b</mark>')).toBe('&lt;script&gt; &lt;mark class="x"&gt;b</mark>');
});
```

- [ ] **Step 2: Vérifier l'échec** — `cd library && pnpm test -- layout highlight` → FAIL.

- [ ] **Step 3: Configuration**

`library/next.config.js` : conserver `withNextIntl` et `transpilePackages` ; `images.remotePatterns` = `https://media.pssfp.org`, `https://*.pssfp.org`, `http://127.0.0.1:9000`, `http://localhost:9000` ; en-têtes de sécurité (`X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-Frame-Options: DENY`, `Permissions-Policy: camera=(), microphone=(), geolocation=()`) via `headers()`.

`library/app/layout.tsx` : `metadataBase: new URL(process.env.NEXT_PUBLIC_SITE_URL ?? 'https://bibliotheque.pssfp.org')`, titres et description issus de `getTranslations('meta')` via `generateMetadata` ; rendre `<SiteHeader/>`, `<main id="main" tabIndex={-1}>`, `<SiteFooter/>` ; garder les trois polices ; fond `bg-pssfp-ivoire`, texte `text-pssfp-graphite`. `globals.css` : garder les variables existantes, ajouter `@media (prefers-reduced-motion: reduce) { *,*::before,*::after { animation-duration:.01ms!important; transition-duration:.01ms!important; scroll-behavior:auto!important } }` et `.scrollbar-none` (pour le carrousel).

- [ ] **Step 4: Client API et utilitaires**

`library/lib/api/types.ts` :

```ts
export type DocumentType =
  | 'memoire' | 'these' | 'ouvrage_numerique' | 'texte_loi' | 'article_scientifique'
  | 'cours' | 'acte_conference' | 'rapport_stage' | 'bulletin' | 'ressource_pedagogique';
export type AccessMode = 'libre' | 'reserve' | 'payant';
export type SortKey = 'recent' | 'ancien' | 'titre' | 'populaire';
export type AccessReason = 'public' | 'reserved' | 'paid' | 'unpublished' | 'external';

export const DOCUMENT_TYPES: readonly DocumentType[] = ['memoire', 'these', 'ouvrage_numerique', 'texte_loi', 'article_scientifique', 'cours', 'acte_conference', 'rapport_stage', 'bulletin', 'ressource_pedagogique'];
export const SORT_KEYS: readonly SortKey[] = ['recent', 'ancien', 'titre', 'populaire'];
export const ACCESS_MODES: readonly AccessMode[] = ['libre', 'reserve', 'payant'];

export interface MotCleRef { slug: string; label: string }

export interface DocumentSummary {
  uuid: string; slug: string; type: DocumentType; type_label: string;
  title: string; subtitle: string | null; year: number | null; language: string;
  specialite: string | null; promotion: number | null;
  authors: string[]; mots_cles: MotCleRef[]; cover_url: string | null;
  access_mode: AccessMode; price_xaf: number | null; published_at: string | null;
}

export interface AccessDecision { allowed: boolean; reason: AccessReason }

export interface DocumentDetail extends DocumentSummary {
  abstract: string | null; journal: string | null; doi: string | null; isbn: string | null;
  pages_count: number | null; file_size_bytes: number | null; external_url: string | null;
  views_count: number; access: AccessDecision;
}

export interface PageMeta { current_page: number; last_page: number; per_page: number; total: number }
export interface Paginated<T> { data: T[]; meta: PageMeta }
export interface LibraryStats { total: number; by_type: Partial<Record<DocumentType, number>>; paid_count: number }
export interface MotCle { slug: string; label: string; parent_slug: string | null }

export interface SearchResponse {
  data: DocumentSummary[];
  highlights: Record<string, string>;
  meta: { total: number; page: number; per_page: number; last_page: number; facets: { type?: Partial<Record<DocumentType, number>>; access_mode?: Partial<Record<AccessMode, number>> } };
}

export interface CatalogueFilters {
  type?: DocumentType; specialite?: string; promotion?: number; annee_min?: number; annee_max?: number;
  langue?: string; acces?: AccessMode; mot_cle?: string; tri?: SortKey;
}

export type ApiResult<T> = { ok: true; data: T } | { ok: false; error: { message: string; status: number } };
```

`library/lib/api/client.ts` :

```ts
import type { ApiResult } from './types';

export const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/v1';

interface GetOptions { revalidate?: number; signal?: AbortSignal; timeoutMs?: number }

/** GET JSON sur l'API ; ne lève jamais : renvoie un ApiResult (status 0 = réseau). */
export async function apiGet<T>(path: string, options: GetOptions = {}): Promise<ApiResult<T>> {
  const url = `${API_BASE_URL}${path.startsWith('/') ? path : `/${path}`}`;
  const timeout = AbortSignal.timeout(options.timeoutMs ?? 5000);
  const signal = options.signal ? AbortSignal.any([options.signal, timeout]) : timeout;
  try {
    const res = await fetch(url, {
      headers: { Accept: 'application/json', 'Accept-Language': 'fr' },
      next: options.revalidate !== undefined ? { revalidate: options.revalidate } : undefined,
      signal,
    });
    if (!res.ok) return { ok: false, error: { message: `API ${res.status}`, status: res.status } };
    return { ok: true, data: (await res.json()) as T };
  } catch (e) {
    if (options.signal?.aborted) throw e;
    return { ok: false, error: { message: e instanceof Error ? e.message : 'network', status: 0 } };
  }
}
```

(Si `AbortSignal.any` n'est pas disponible avec la cible TypeScript/Node, combiner les signaux manuellement — Node ≥ 20 l'expose.)

`library/lib/api/library.ts` :

```ts
import { apiGet, API_BASE_URL } from './client';
import type { ApiResult, CatalogueFilters, DocumentDetail, DocumentSummary, LibraryStats, MotCle, Paginated, SearchResponse } from './types';
import { filtersToSearchParams } from '../routes';

const ISR = 300;

export function getStats(): Promise<ApiResult<{ data: LibraryStats }>> {
  return apiGet('/library/stats', { revalidate: ISR });
}

export function listDocuments(filters: CatalogueFilters, page = 1, perPage?: number): Promise<ApiResult<Paginated<DocumentSummary>>> {
  const qs = filtersToSearchParams(filters);
  if (page > 1) qs.set('page', String(page));
  if (perPage) qs.set('per_page', String(perPage));
  const s = qs.toString();
  return apiGet(`/library/documents${s ? `?${s}` : ''}`, { revalidate: ISR });
}

export function getDocument(slug: string): Promise<ApiResult<{ data: DocumentDetail; related: DocumentSummary[] }>> {
  return apiGet(`/library/documents/${encodeURIComponent(slug)}`, { revalidate: ISR });
}

export function getMotsCles(): Promise<ApiResult<{ data: MotCle[] }>> {
  return apiGet('/library/mots-cles', { revalidate: 3600 });
}

export function resolveLegacyPath(path: string): Promise<ApiResult<{ data: { to_path: string; status_code: number } }>> {
  return apiGet(`/library/redirects?path=${encodeURIComponent(path)}`, { revalidate: 3600, timeoutMs: 2000 });
}

/** Navigateur uniquement : la recherche part du client (débit par IP, journaux justes). */
export function searchDocuments(q: string, filters: CatalogueFilters, page: number, signal?: AbortSignal): Promise<ApiResult<SearchResponse>> {
  const qs = filtersToSearchParams({ ...filters, tri: undefined });
  qs.set('q', q);
  if (page > 1) qs.set('page', String(page));
  return apiGet(`/library/search?${qs.toString()}`, { signal, timeoutMs: 8000 });
}

/** Navigateur uniquement : compte une consultation (sans bloquer la page). */
export function recordView(uuid: string): void {
  const url = `${API_BASE_URL}/library/documents/${encodeURIComponent(uuid)}/view`;
  if (typeof navigator !== 'undefined' && 'sendBeacon' in navigator && navigator.sendBeacon(url)) return;
  void fetch(url, { method: 'POST', keepalive: true }).catch(() => undefined);
}
```

`library/lib/routes.ts` :

```ts
import { ACCESS_MODES, DOCUMENT_TYPES, SORT_KEYS, type CatalogueFilters, type DocumentType } from './api/types';
import { API_BASE_URL } from './api/client';

const INT_KEYS = ['promotion', 'annee_min', 'annee_max'] as const;

export function filtersToSearchParams(f: CatalogueFilters): URLSearchParams {
  const qs = new URLSearchParams();
  for (const [k, v] of Object.entries(f)) if (v !== undefined && v !== null && v !== '') qs.set(k, String(v));
  return qs;
}

/** Lit des searchParams non fiables : ne garde que les valeurs reconnues. */
export function searchParamsToFilters(sp: URLSearchParams | Record<string, string | string[] | undefined>): CatalogueFilters {
  const get = (k: string): string | undefined => {
    const v = sp instanceof URLSearchParams ? sp.get(k) ?? undefined : sp[k];
    return Array.isArray(v) ? v[0] : v;
  };
  const f: CatalogueFilters = {};
  const type = get('type'); if (type && (DOCUMENT_TYPES as readonly string[]).includes(type)) f.type = type as DocumentType;
  const acces = get('acces'); if (acces && (ACCESS_MODES as readonly string[]).includes(acces)) f.acces = acces as CatalogueFilters['acces'];
  const tri = get('tri'); if (tri && (SORT_KEYS as readonly string[]).includes(tri)) f.tri = tri as CatalogueFilters['tri'];
  for (const k of INT_KEYS) { const n = Number(get(k)); if (Number.isInteger(n) && n > 0) f[k] = n; }
  for (const k of ['specialite', 'langue', 'mot_cle'] as const) { const v = get(k); if (v && /^[a-z0-9-]{1,120}$/.test(v)) f[k] = v; }
  return f;
}

export const collectionHref = (type: DocumentType, f: CatalogueFilters = {}): string => {
  const qs = filtersToSearchParams({ ...f, type: undefined }).toString();
  return `/collections/${type}${qs ? `?${qs}` : ''}`;
};
export const searchHref = (q: string, f: CatalogueFilters = {}): string => {
  const qs = filtersToSearchParams(f); qs.set('q', q); return `/recherche?${qs.toString()}`;
};
export const documentHref = (slug: string): string => `/documents/${encodeURIComponent(slug)}`;
export const downloadHref = (uuid: string): string => `${API_BASE_URL}/library/documents/${encodeURIComponent(uuid)}/download`;
```

`library/lib/highlight.ts` :

```ts
/**
 * Le backend renvoie des titres déjà échappés avec des <mark>. Défense en
 * profondeur : tout est ré-échappé, seuls `<mark>` et `</mark>` exacts sont rétablis.
 */
export function sanitizeHighlight(html: string): string {
  const escaped = html.replace(/&(?!(?:amp|lt|gt|quot|#39);)/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  return escaped.replace(/&lt;mark&gt;/g, '<mark>').replace(/&lt;\/mark&gt;/g, '</mark>');
}
```

- [ ] **Step 5: Mise en page**

- `SiteHeader` (client) : copie adaptée de `frontend/components/SiteHeader` (lien d'évitement `sr-only focus:not-sr-only`, en-tête collant `bg-white/95 backdrop-blur border-b`, marque « PSSFP · Bibliothèque » avec `<Logo variant="mark"/>`, navigation desktop : Collections (lien vers `/#collections`), Recherche (`/recherche`), À propos (`/a-propos`), lien externe « Site du PSSFP » (`NEXT_PUBLIC_MAIN_SITE_URL`) ; bouton burger `aria-expanded`/`aria-controls="mobile-menu"` sous `md`). Pas de « Se connecter » (sous-projet 4).
- `MobileDrawer` : `role="dialog" aria-modal="true" id="mobile-menu" aria-labelledby`, fermeture Échap / fond / changement de route, focus piégé et rendu au bouton à la fermeture, verrou du défilement.
- `SiteFooter` : fond `bg-pssfp-prune text-white`, trois colonnes repliées en une sur mobile : présentation courte, liens (Collections par type, Recherche, À propos, Site du PSSFP, FOAD si `NEXT_PUBLIC_FOAD_URL`), contact bibliothécaire (adresse du site principal) ; mention © avec l'année courante.
- `Breadcrumbs` : même API et balisage que `frontend/components/Breadcrumbs` (`nav aria-label`, `aria-current="page"`, `data-testid="breadcrumb-current"`).
- `not-found.tsx` : titre `h1`, phrase et lien vers l'accueil et la recherche, tout via `getTranslations('notFound')`.
- `messages/fr.json` : espaces `meta`, `nav`, `footer`, `notFound`, `home`, `catalogue`, `document`, `search`, `about`, `access`, `types` (libellés au singulier et au pluriel des 10 types), `sort`, `filters`, `pagination`, `states`. Supprimer les clés inutilisées (`apiHealth*`, `theses`, `articles`, `courses`, `myAccount`).

- [ ] **Step 6: Vérifier** — `cd library && pnpm lint && pnpm typecheck && pnpm test -- layout highlight` → PASS.

- [ ] **Step 7: Commit** — `feat(biblio-front): socle, client API, en-tete et pied de page accessibles`.

---

### Task 3: Composants documentaires

**Files:**
- Create: `library/components/document/DocumentCover.tsx`, `AccessBadge.tsx`, `DocumentCard.tsx`, `AccessAction.tsx`, `CitationBox.tsx`, `ViewBeacon.tsx`
- Create: `library/components/catalogue/Pagination.tsx`, `StateMessage.tsx`
- Create: `library/app/dev/composants/page.tsx` **uniquement si** `process.env.NODE_ENV !== 'production'` (sinon `notFound()`) — page de démonstration avec données fictives en dur dans un objet (pas de texte d'interface en dur : libellés via `t()`) pour tester les composants sans backend
- Test: `library/tests/playwright/composants.spec.ts`

**Interfaces:**
- Consumes: types et `routes.ts` (tâche 2).
- Produces:
  - `<DocumentCover doc={DocumentSummary} size="sm"|"md"|"lg" priority?/>` : `next/image` si `cover_url` (alt = titre), sinon couverture typographique (dégradé plat de la couleur du type, titre tronqué en Cormorant, type en DM Sans) ; ratio 3:4 ; `aria-hidden` sur la couverture typographique (le titre est déjà lu dans la carte).
  - Couleurs par type : `memoire`/`rapport_stage` prune, `these` bleu pétrole, `texte_loi` graphite, `ouvrage_numerique` or (texte prune foncé), `cours`/`ressource_pedagogique` lavande (texte prune foncé), autres prune clair — contraste du texte ≥ 4.5:1.
  - `<AccessBadge mode={AccessMode} price?={number|null}/>` : « Libre » vert sombre sur vert pâle, « Réservé » prune sur lavande pâle, « Payant · 2 500 FCFA » brun sur or pâle (format `Intl.NumberFormat('fr-FR')`) ; icône lucide décorative (`aria-hidden`).
  - `<DocumentCard doc highlight?={string} layout="grid"|"row"/>` : `article` avec lien principal sur le titre (`documentHref`), couverture, type, année/promotion, auteurs (2 max + « et al. »), badge d'accès ; `highlight` rendu via `sanitizeHighlight` + `dangerouslySetInnerHTML` ; `data-testid="document-card-{slug}"` ; survol : élévation `shadow-pssfp-elevated` et translation de 2 px (désactivée en mouvement réduit) ; focus visible sur le lien.
  - `<AccessAction doc={DocumentDetail}/>` : selon `doc.access.reason` — `public` → lien bouton « Télécharger le PDF » (`downloadHref(uuid)`, taille formatée si `file_size_bytes`) ; `external` → lien « Consulter en ligne » (`rel="noopener noreferrer" target="_blank"`, mention nouvelle fenêtre pour lecteurs d'écran) ; `reserved` → encadré « Réservé aux auditeurs — bientôt disponible » sans lien ; `paid` → encadré « Payant — bientôt disponible à l'achat » avec le prix, sans lien. `data-testid="access-action"` et `data-reason`.
  - `<CitationBox doc/>` (client) : citation APA `Auteur1, Auteur2 (Année). Titre. Type. Bibliothèque PSSFP. URL` ; bouton « Copier » → `navigator.clipboard.writeText`, annonce `aria-live="polite"` « Citation copiée ».
  - `<ViewBeacon uuid/>` (client) : `useEffect(() => recordView(uuid), [uuid])`, ne rend rien.
  - `<Pagination page lastPage hrefFor={(p:number)=>string}/>` : `nav aria-label`, précédent/suivant, numéros avec ellipses (1 … p-1 p p+1 … last), `aria-current="page"`, liens (pas de boutons) pour rester navigable sans JavaScript.
  - `<StateMessage kind="empty"|"error" title body? action?/>` : `role="status"` (vide) ou `role="alert"` (erreur), `data-testid="state-empty"|"state-error"`.

- [ ] **Step 1: Test qui échoue** — `library/tests/playwright/composants.spec.ts` : visite `/dev/composants` ; vérifie : une carte par mode d'accès avec le bon badge (`Libre`, `Réservé`, `Payant · 2 500 FCFA`), la couverture typographique quand `cover_url` est nul, `AccessAction` pour les 4 raisons (`data-reason` et présence/absence de lien), le surlignage n'injecte pas de balise `<img>` (titre piégé `<img src=x onerror=…><mark>a</mark>` → aucun élément `img` dans la carte, un `mark`), la copie de citation annonce « Citation copiée » (accorder `clipboard-read`/`clipboard-write` au contexte), la pagination marque la page courante, axe sans violation grave. Écrire le test complet avant les composants.
- [ ] **Step 2: Vérifier l'échec.**
- [ ] **Step 3: Implémenter** les composants et la page de démonstration (données fictives, aucune donnée réelle).
- [ ] **Step 4: Vérifier** — `pnpm lint && pnpm typecheck && pnpm test -- composants` → PASS ; `pnpm build` → la page `/dev/composants` renvoie 404 en production (vérifier avec `pnpm start` puis `curl -o /dev/null -w "%{http_code}" localhost:6002/dev/composants` → 404).
- [ ] **Step 5: Commit** — `feat(biblio-front): cartes, couvertures, badges et actions d'acces`.

---

### Task 4: Accueil (A + B + C)

**Files:**
- Create: `library/components/home/HomeHero.tsx`, `SearchForm.tsx`, `CollectionsMosaic.tsx`, `LatestDocuments.tsx`, `MostRead.tsx`
- Modify: `library/app/page.tsx`
- Test: `library/tests/playwright/accueil.spec.ts`

**Interfaces:**
- Consumes: `getStats()`, `listDocuments({tri:'recent'},1,6)`, `listDocuments({tri:'populaire'},1,10)`, `DocumentCard`, `DocumentCover`, `searchHref`, `collectionHref`.
- Produces: `<SearchForm defaultQuery? size="hero"|"compact"/>` (formulaire GET vers `/recherche`, champ `q` `type="search"` avec `label` visible ou `sr-only`, `minLength=2`, bouton « Rechercher ») — réutilisé par les tâches 5 à 7.

Contenu (référence visuelle : maquettes A, B, C) :
1. **Héros (A + B)** — fond ivoire, grille 2 colonnes ≥ `lg` : à gauche sur-titre or `font-ui uppercase tracking-wide` « {total} documents · finances publiques » (masqué si les stats sont indisponibles), `h1` Cormorant `text-pssfp-h1` « Le savoir des finances publiques, en libre consultation. », `SearchForm size="hero"`, suggestions (liens vers `searchHref` : « marchés publics », « TVA », « décentralisation », « audit interne », « loi de finances ») puis puces de types (liens `collectionHref`) ; à droite, sur `lg`, les 3 dernières nouveautés en liste compacte (couverture `sm` + titre + type + badge). Un seul `h1` sur la page.
2. **Mosaïque des collections (C)** — `section id="collections" aria-labelledby` ; grille bento : tuile Mémoires 2×2 prune (compteur, « {n} travaux · 13 promotions »), Thèses bleu pétrole, Législation et Ouvrages blanc bordé, Cours (badge Réservé) lavande, **tuile Ouvrages premium or uniquement si `paid_count > 0`** (« Ouvrages premium — bientôt disponibles », lien `collectionHref('ouvrage_numerique', {acces:'payant'})`) ; toutes les tuiles sont des liens entiers ; compteurs issus de `stats.by_type` (tuile masquée si 0) ; sur mobile, une colonne puis deux à partir de `sm`.
3. **Nouveautés (A)** — 6 cartes `layout="grid"` (2 colonnes mobile, 3 `md`, 6 `xl`). Pas de page « toutes les nouveautés » en V1, donc pas de lien « voir tout ».
4. **Les plus lus (C)** — bandeau horizontal `overflow-x-auto snap-x scrollbar-none` de 10 couvertures `md` avec titre sous la couverture, boutons précédent/suivant (`aria-controls`, défilement d'une largeur de carte, masqués si mouvement réduit n'est pas pertinent — ils restent, seul le défilement doux est coupé), liste `ol` pour la sémantique.
5. États : chaque section gère l'échec API indépendamment (`StateMessage kind="error"` discret) ; si tout échoue, la page reste utilisable (héros + recherche).

`app/page.tsx` : `export const revalidate = 300;` ; `Promise.all` des trois appels ; `generateMetadata` (titre « Accueil », description, Open Graph) ; JSON-LD `WebSite` + `SearchAction` (`urlTemplate` `/recherche?q={search_term_string}`) via un composant `JsonLd` copié de `frontend/components/JsonLd`.

- [ ] **Step 1: Test qui échoue** — `accueil.spec.ts` : (a) toujours : un seul `h1`, le formulaire de recherche soumet vers `/recherche?q=tva` ; axe sans violation grave ; aucun débordement à 320 px ; (b) si l'API répond (`request.get(API + '/library/stats')` ok, sinon `test.skip`) : la tuile Mémoires affiche le compteur de l'API, 6 cartes de nouveautés au plus, la tuile premium apparaît ssi `paid_count > 0`, la liste « Les plus lus » contient au moins un élément.
- [ ] **Step 2: Vérifier l'échec.**
- [ ] **Step 3: Implémenter.** Images : `priority` uniquement sur la première couverture visible du héros ; `sizes` renseigné partout.
- [ ] **Step 4: Vérifier** — avec le backend local seedé (`php artisan serve --port=8000` + `LibraryDemoSeeder`) puis sans backend : `pnpm test -- accueil` PASS dans les deux cas (le bloc (b) est ignoré sans backend). Capture d'écran 320/768/1440 jointe au rapport (`page.screenshot` dans un test `@visuel` ignoré en CI, ou manuellement).
- [ ] **Step 5: Commit** — `feat(biblio-front): accueil avec heros, mosaique des collections, nouveautes et plus lus`.

---

### Task 5: Collections filtrables

**Files:**
- Create: `library/app/collections/[type]/page.tsx`, `library/components/catalogue/FilterPanel.tsx`, `SortSelect.tsx`, `ActiveFilters.tsx`
- Test: `library/tests/playwright/collections.spec.ts`

**Interfaces:**
- Consumes: `listDocuments`, `getMotsCles`, `searchParamsToFilters`, `collectionHref`, `DocumentCard`, `Pagination`, `StateMessage`, `Breadcrumbs`, `SearchForm size="compact"`.

Comportement :
- `params.type` hors `DOCUMENT_TYPES` → `notFound()`. Filtres lus par `searchParamsToFilters` (valeurs inconnues ignorées), page `?page=` entier ≥ 1.
- Mise en page B : fil d'Ariane (Accueil › {Type pluriel}), `h1` type pluriel + compteur `meta.total`, recherche compacte limitée au type (champ caché `type`), à gauche ≥ `lg` un **formulaire GET** `FilterPanel` (fonctionne sans JavaScript) : accès (radios Libre/Réservé/Payant/Tous), année min/max (`input type=number`), langue (select), promotion (si type `memoire`), mot-clé (select depuis `getMotsCles`) ; boutons « Appliquer » et « Réinitialiser » (lien sans paramètres) ; sur mobile le panneau est dans un `<details>` « Filtrer » (accessible nativement). `SortSelect` : formulaire GET avec `select` + bouton « Trier » (amélioré en client : soumission au changement, bouton masqué visuellement).
- `ActiveFilters` : puces des filtres actifs, chacune un lien qui retire ce filtre, `aria-label` « Retirer le filtre … ».
- Liste : cartes `layout="row"` sur mobile, grille ≥ `md` ; `Pagination` qui conserve les filtres ; état vide avec lien de réinitialisation ; état d'erreur.
- `generateMetadata` : titre « {Type pluriel} », `alternates.canonical` sans paramètres de filtre ; `robots: { index: false }` dès qu'un filtre ou `page>1` est présent.
- `export const revalidate = 300;`.

- [ ] **Step 1: Test qui échoue** — `collections.spec.ts` : `/collections/inconnu` → 404 ; `/collections/memoire` a un `h1`, un fil d'Ariane, axe sans violation grave ; le formulaire de filtres est soumis sans JavaScript (`test.use({ javaScriptEnabled: false })` dans un `describe`) et l'URL contient `acces=libre` ; un paramètre invalide (`?acces=gratuit&annee_min=abc`) ne casse pas la page ; avec API : les cartes affichées ont le type demandé (`data-testid` + texte du type), le tri `populaire` change l'ordre par rapport à `recent` quand il y a ≥ 2 documents, la pagination conserve `acces` dans ses liens, et `ActiveFilters` retire un filtre.
- [ ] **Step 2: Vérifier l'échec.** - [ ] **Step 3: Implémenter.** - [ ] **Step 4: Vérifier** (avec et sans backend). - [ ] **Step 5: Commit** — `feat(biblio-front): collections filtrables et paginees`.

---

### Task 6: Fiche document

**Files:**
- Create: `library/app/documents/[slug]/page.tsx`
- Test: `library/tests/playwright/fiche.spec.ts`

**Interfaces:**
- Consumes: `getDocument`, `DocumentCover`, `AccessBadge`, `AccessAction`, `CitationBox`, `ViewBeacon`, `DocumentCard`, `Breadcrumbs`, `JsonLd`.

Contenu :
- 404 si l'API renvoie 404 (`notFound()`), état d'erreur sinon.
- Fil d'Ariane : Accueil › {Type pluriel} (lien collection) › titre court.
- Grille ≥ `lg` : couverture `lg` (sticky) à gauche ; à droite type + badge d'accès, `h1` titre, sous-titre, auteurs, métadonnées en `dl` (année, promotion, spécialité, langue, pages, revue, DOI en lien `https://doi.org/…`, ISBN), `AccessAction`, résumé (`h2` « Résumé », paragraphes conservant les retours à la ligne), mots-clés en liens vers `collectionHref(doc.type, { mot_cle })` (la recherche exige `q`, d'où la collection filtrée), `CitationBox`.
- « Documents liés » : jusqu'à 4 `DocumentCard` (`related`), section masquée si vide.
- `ViewBeacon` (une consultation par affichage côté navigateur).
- `generateMetadata` : titre, description = 160 premiers caractères du résumé, Open Graph (`type: 'book'` pour ouvrage/thèse/mémoire sinon `article`, image = `cover_url`), `alternates.canonical`.
- JSON-LD : `Book` (ouvrage_numerique, these, memoire) ou `ScholarlyArticle` (article_scientifique) ou `CreativeWork` (autres) avec `name`, `author` (Person[]), `datePublished` (année), `inLanguage`, `isAccessibleForFree` (= `access_mode === 'libre'`), `publisher` (Organization PSSFP), `url`.
- `export const revalidate = 300;` et `generateStaticParams` absent (rendu à la demande, puis mis en cache).

- [ ] **Step 1: Test qui échoue** — `fiche.spec.ts` : `/documents/inexistant-xyz` → 404 ; avec API (prendre le premier document de `/library/documents` via `request.get`, sinon skip) : `h1` = titre de l'API, fil d'Ariane, `access-action` présent avec `data-reason` cohérent avec `access_mode` (libre→public ou external, reserve→reserved, payant→paid), le JSON-LD est un JSON valide contenant `name`, un appel `POST …/view` est émis au chargement (`page.waitForRequest`), axe sans violation grave ; pour un document payant (filtre `acces=payant`, sinon skip) : aucun lien de téléchargement, le prix est affiché.
- [ ] **Step 2: Vérifier l'échec.** - [ ] **Step 3: Implémenter.** - [ ] **Step 4: Vérifier.** - [ ] **Step 5: Commit** — `feat(biblio-front): fiche document avec acces, citation et documents lies`.

---

### Task 7: Recherche

**Files:**
- Create: `library/app/recherche/page.tsx` (serveur : coquille + métadonnées `robots: noindex`), `library/components/search/SearchExperience.tsx` (client), `FacetList.tsx`
- Test: `library/tests/playwright/recherche.spec.ts`

**Interfaces:**
- Consumes: `searchDocuments`, `searchParamsToFilters`, `searchHref`, `SearchForm`, `DocumentCard` (avec `highlight`), `Pagination` (variante bouton côté client ou liens `searchHref` — **utiliser des liens** pour garder l'URL partageable), `StateMessage`.

Comportement :
- La page serveur rend `h1` « Recherche », `SearchForm` prérempli, et `<SearchExperience/>` dans un `Suspense`.
- `SearchExperience` lit `useSearchParams()` ; si `q` < 2 caractères → invite « Saisissez au moins deux caractères » + suggestions ; sinon appelle `searchDocuments` (annule la requête précédente via `AbortController`), affiche un squelette de chargement (`aria-busy="true"` sur la région résultats), puis : nombre de résultats annoncé dans une région `aria-live="polite"` (« 12 résultats pour « tva » »), facettes (type, accès) en liens qui ajoutent/retirent le filtre avec compteurs, cartes avec `highlights[uuid]`, pagination ; zéro résultat → message utile (« Aucun résultat pour … ») + liens vers les collections + suggestion de retirer les filtres ; erreur réseau/API → `StateMessage kind="error"` avec bouton « Réessayer ».
- Les filtres de facettes et la pagination modifient l'URL (`router.push` via liens `next/link`) ; le bouton Retour du navigateur restaure l'état.
- Mise en page B (facettes à gauche ≥ `lg`, au-dessus sur mobile dans un `<details>`).

- [ ] **Step 1: Test qui échoue** — `recherche.spec.ts` : `/recherche` sans `q` affiche l'invite ; `/recherche?q=a` affiche l'invite (moins de 2 caractères) ; **la requête de recherche part du navigateur** (`page.waitForRequest(r => r.url().includes('/library/search') && r.method()==='GET')`) ; si l'API est injoignable → `state-error` visible et « Réessayer » relance une requête ; avec API seedée : `?q=decentralisaton` (faute volontaire) renvoie ≥ 1 carte (si Meilisearch actif ; avec le repli PostgreSQL utiliser `?q=decentralisation`) contenant un `mark`, la région `aria-live` annonce le nombre, cliquer une facette de type ajoute `type=` à l'URL, Retour restaure ; axe sans violation grave.
- [ ] **Step 2: Vérifier l'échec.** - [ ] **Step 3: Implémenter.** - [ ] **Step 4: Vérifier.** - [ ] **Step 5: Commit** — `feat(biblio-front): recherche cote navigateur avec facettes et surlignage`.

---

### Task 8: Anciennes URL, SEO, page À propos, CI et vérification finale

**Files:**
- Create: `library/middleware.ts`, `library/app/sitemap.ts`, `library/app/robots.ts`, `library/app/a-propos/page.tsx`
- Modify: `.github/workflows/lighthouse.yml` (URLs library : `/`, `/recherche?q=budget`, `/collections/memoire`), `library/CLAUDE.md` (routes réelles V1, décisions recherche/consultations), `docs/specs/module-3-bibliotheque-v1-2026-10.md` §6.2 (recherche rendue côté navigateur, consultations par balise — décisions du 2026-10-17)
- Test: `library/tests/playwright/redirections.spec.ts`, `library/tests/playwright/seo.spec.ts`

**Interfaces:**
- Consumes: `resolveLegacyPath`.

`library/middleware.ts` :

```ts
import { NextResponse, type NextRequest } from 'next/server';
import { resolveLegacyPath } from './lib/api/library';

/** Chemins servis par l'app : jamais résolus comme anciennes URL. */
const APP_PREFIXES = ['/collections', '/recherche', '/documents', '/a-propos', '/dev', '/_next', '/logos', '/icon', '/apple-icon', '/favicon', '/sitemap.xml', '/robots.txt'];

export async function middleware(req: NextRequest): Promise<NextResponse> {
  const { pathname } = req.nextUrl;
  if (pathname === '/' || APP_PREFIXES.some((p) => pathname === p || pathname.startsWith(`${p}/`))) {
    return NextResponse.next();
  }

  const r = await resolveLegacyPath(pathname);
  if (r.ok) {
    const to = r.data.data.to_path;
    // Chemin interne uniquement (pas de redirection ouverte).
    if (/^\/(?![/\\])/.test(to)) {
      return NextResponse.redirect(new URL(to, req.url), r.data.data.status_code === 302 ? 302 : 301);
    }
  }
  return NextResponse.next();
}

export const config = { matcher: ['/((?!_next/static|_next/image).*)'] };
```

(Si le runtime edge refuse `AbortSignal.any` ou `next.revalidate` dans `apiGet`, écrire dans le middleware un `fetch` direct avec `AbortSignal.timeout(2000)` et `cache: 'force-cache'` ; le comportement attendu reste : 301 si trouvé, sinon suite normale → 404.)

- `sitemap.ts` : accueil, `/recherche` exclu (noindex), `/a-propos`, une entrée par collection non vide (`getStats`), et toutes les fiches publiées (pagination de `listDocuments({tri:'recent'}, page, 50)` jusqu'à `last_page`, plafond 5 000 entrées) ; `try/catch` → sitemap minimal si l'API est indisponible ; `lastModified` = `published_at`.
- `robots.ts` : `allow: '/'`, `disallow: ['/dev/', '/recherche']`, `sitemap: {SITE_URL}/sitemap.xml`.
- `/a-propos` : présentation, règles d'accès (Libre / Réservé / Payant — bientôt), contact (lien vers la page contact du site principal), statique (`export const dynamic = 'force-static'`), tout via `getTranslations('about')`.

- [ ] **Step 1: Tests qui échouent** — `redirections.spec.ts` : avec API (sinon skip), utiliser la redirection créée par `LibraryDemoSeeder` (tâche 1) : `request.get('/index.php/textes-et-lois', { maxRedirects: 0 })` → 301 et `location` se termine par `/collections/texte_loi` ; chemin inconnu `/index.php/inconnu` → 404 ; une route de l'app (`/recherche`) n'est jamais redirigée. `seo.spec.ts` : `robots.txt` contient `Sitemap:` et `Disallow: /dev/` ; `sitemap.xml` est un XML valide contenant l'URL d'accueil ; l'accueil a `link[rel=canonical]` et `meta[name=description]` ; `/a-propos` a un `h1` et passe axe.
- [ ] **Step 2: Vérifier l'échec.** - [ ] **Step 3: Implémenter.** - [ ] **Step 4: Vérification finale** — depuis `library/` : `pnpm lint && pnpm typecheck && pnpm build && pnpm test` (sans backend : tests à données ignorés, reste vert) puis avec le backend seedé (`PLAYWRIGHT_BASE_URL` par défaut, API sur 8000) : `pnpm test` entièrement vert ; Lighthouse local (`pnpm build && pnpm start --port 6002` puis `npx lighthouse http://localhost:6002/ --preset=desktop --only-categories=performance,accessibility,seo,best-practices --quiet --chrome-flags="--headless"`) ≥ 85 sur accueil, recherche, une fiche — consigner les scores dans le rapport. Revue par `a11y-reviewer` et `i18n-reviewer` (contrôleur).
- [ ] **Step 5: Commit** — `feat(biblio-front): redirections des anciennes URL, sitemap, robots et page a propos`.

---

## Hors de ce plan
- Plan 3 : import Joomla et bascule DNS/nginx/pm2 (vhost `bibliotheque.pssfp.org`, processus `pssfp-library`).
- Sous-projet 4 (comptes, cours filigranés, « Se connecter ») et 5 (paiement).
