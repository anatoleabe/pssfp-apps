# /library — bibliothèque virtuelle bibliotheque.pssfp.org

> Lu automatiquement par Claude Code à chaque session ouverte dans ce dossier.

## Mission

App Next.js 14 servant la bibliothèque virtuelle sur `bibliotheque.pssfp.org` (domaine canonique, `NEXT_PUBLIC_SITE_URL`). V1 : catalogue public en lecture seule (sans compte), recherche Meilisearch via l'API Laravel, fiches avec accès différencié par mode (`libre` / `reserve` / `payant`). Spec de référence : `docs/specs/module-3-bibliotheque-v1-2026-10.md` (§6 pour ce dossier). Comptes, cours filigranés et paiement : sous-projets 4 et 5 (hors V1).

## Principes spécifiques biblio

- **Recherche < 500 ms** côté API (CDC v2.0 §K.2). Toujours via `GET /v1/library/search` (jamais Meilisearch en direct : l'API applique les règles d'accès).
- **Accès** : le mode d'accès et la décision (`access.allowed`, `access.reason`) viennent de l'API ; le front ne fait qu'afficher (Télécharger / Réservé — bientôt / Payant — bientôt / Consulter le lien). Téléchargement via `/v1/library/documents/{uuid}/download` (URL signée 30 min côté API).
- **Identifiants publics** : `slug` dans les URL de pages, `uuid` pour téléchargement et consultation. Jamais d'`id`.

## Routes réelles (V1)

```
app/
├── layout.tsx                 # en-tête, pied de page, lien d'évitement #main
├── page.tsx                   # / : héros + recherche, collections, nouveautés, plus lus (ISR 5 min)
├── collections/[type]/page.tsx  # catalogue filtrable, trié, paginé (ISR 5 min)
├── recherche/page.tsx         # coquille serveur ; résultats demandés par le navigateur (noindex)
├── documents/[slug]/page.tsx  # fiche (ISR 5 min, generateStaticParams → [])
├── a-propos/page.tsx          # statique (force-static)
├── dev/composants/page.tsx    # vitrine des composants (404 en production, interdite dans robots)
├── sitemap.ts · robots.ts     # sitemap (ISR 1 h, plafond 5 000 fiches) et robots
├── error.tsx · not-found.tsx
└── icon.png · apple-icon.png
middleware.ts                  # anciennes URL Joomla → 301 via /v1/library/redirects
```

## Décisions à respecter

- **`unwrapOrThrow` (`lib/api/unwrap.ts`)** pour toute donnée indispensable d'une page ISR : 404 → `notFound()`, autre échec → exception (l'ISR garde la dernière page valide ; au premier rendu, `app/error.tsx`). Jamais d'état d'erreur mis en cache. Pendant `next build` sans API (CI), `isBuildPhase()` → page de repli au lieu d'un build en échec.
- **Fiches** : `generateStaticParams` renvoie `[]` — aucune fiche au build, mais mise en cache ISR réelle au premier accès.
- **Recherche côté navigateur** (`components/search/SearchExperience.tsx`) : débit par IP et journaux de recherche portent sur le visiteur, pas sur le serveur Next. Erreurs dans la page (`role="alert"`, message dédié sur 429). Le squelette réserve la place des facettes et assez de hauteur pour ne pas décaler la page (CLS).
- **Consultations par balise** : `ViewBeacon` envoie `POST /v1/library/documents/{uuid}/view` depuis le navigateur (`sendBeacon`, repli `fetch keepalive`) ; rien n'est compté côté serveur (pages ISR).
- **Anciennes URL** (`middleware.ts`) : chemins hors routes de l'app et hors fichiers statiques usuels → `resolveLegacyPath` (délai 2 s) ; trouvé → 301/302 vers un chemin interne uniquement ; réponses mémorisées 10 min par processus ; toute erreur → suite normale (404). Garder `APP_PREFIXES` synchronisé avec `app/` et `public/`.
- **SEO** : `/recherche` noindex + `Disallow`, exclue du sitemap ; variantes filtrées/paginées des collections noindex avec canonique nue.
- **Textes** : tout passe par `next-intl` (`messages/fr.json`), y compris `aria-label`, `alt`, `title`.

## Tests

Playwright (`tests/playwright/*.spec.ts`), verts **avec ou sans backend** (la CI ne démarre pas l'API) : les tests de données se marquent `test.skip()` si l'API est injoignable, et échouent explicitement sur un 429. Specs : `accueil`, `collections`, `recherche`, `fiche`, `layout`, `redirections`, `seo`, plus les tests unitaires de `lib/` (`routes`, `pagination`, `highlight`, `citation`, `api-client`, `composants`). Serveur de test : `pnpm dev --port 6002`. Les redirections utilisent celle de `LibraryDemoSeeder` (`/index.php/textes-et-lois` → `/collections/texte_loi`).

### Avec l'API locale (`cd backend && php artisan serve --port=8000`)

L'API bibliothèque limite le débit par IP (120 requêtes/min par défaut) et tout le trafic local (workers Playwright et serveur Next) vient de 127.0.0.1 : la suite complète peut atteindre la limite (réponses 429, frontières d'erreur, tests de données ignorés ou en échec). Relever le plafond dans `backend/.env` (non commité), puis relancer `php artisan serve` :

```
LIBRARY_RATE_LIMIT=600
```

Les tests de la fiche échouent explicitement sur un 429 au lieu de passer à tort. Autres réglages (`LIBRARY_SEARCH_RATE_LIMIT`, `LIBRARY_DOWNLOAD_RATE_LIMIT`, `LIBRARY_VIEW_RATE_LIMIT`, `LIBRARY_RATE_LIMIT_EXEMPT_IPS`) : voir `.env.example` à la racine.

## Performance cible

Lighthouse ≥ 85 sur les 4 dimensions (CDC v2.0 §K.2), mesuré en local sur `pnpm build && pnpm start --port 6002` avec le backend seedé. Exception assumée : `/recherche` plafonne à ~63 en SEO (noindex voulu, audit `is-crawlable`).

## Variables d'env

```
NEXT_PUBLIC_API_URL=http://localhost:8000/v1
NEXT_PUBLIC_SITE_URL=https://bibliotheque.pssfp.org
NEXT_PUBLIC_MAIN_SITE_URL=http://localhost:6001
NEXT_PUBLIC_FOAD_URL=https://foad.pssfp.net
```

## Sous-agent dédié

`.claude/agents/biblio-reviewer.md` — relit les diffs biblio (access_level, URLs signées, filigrane).
