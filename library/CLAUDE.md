# /library — bibliothèque virtuelle library.pssfp.org

> Lu automatiquement par Claude Code à chaque session ouverte dans ce dossier.

## Mission

App Next.js 14 servant la bibliothèque virtuelle sur `library.pssfp.org` (domaine canonique, `NEXT_PUBLIC_SITE_URL`). V1 : catalogue public en lecture seule (sans compte), recherche Meilisearch via l'API Laravel, fiches avec accès différencié par mode (`libre` / `reserve` / `payant`). Spec de référence : `docs/specs/module-3-bibliotheque-v1-2026-10.md` (§6 pour ce dossier). Comptes, cours filigranés et paiement : sous-projets 4 et 5 (hors V1).

## Principes spécifiques biblio

- **Recherche < 500 ms** côté API (CDC v2.0 §K.2). Toujours via `GET /v1/library/search` (jamais Meilisearch en direct : l'API applique les règles d'accès).
- **Accès** : le mode d'accès et la décision (`access.allowed`, `access.reason`) viennent de l'API ; le front ne fait qu'afficher (Télécharger / Réservé — bientôt / Payant — bientôt / Consulter le lien). Téléchargement via `/v1/library/documents/{uuid}/download` (URL signée 30 min côté API).
- **Identifiants publics** : `slug` dans les URL de pages, `uuid` pour téléchargement et consultation. Jamais d'`id`.

## Routes réelles (V1)

```
app/
├── layout.tsx                 # en-tête, pied de page, lien d'évitement #main
├── page.tsx                   # / : héros + recherche, indicateurs, collections, nouveautés, législation, thèmes, plus lus (ISR 5 min)
├── collections/[type]/page.tsx  # catalogue filtrable, trié, paginé (dynamique : searchParams ; cache de données 5 min, pas d'ISR)
├── recherche/page.tsx         # coquille serveur ; résultats demandés par le navigateur (noindex)
├── documents/[slug]/page.tsx  # fiche (ISR 5 min, generateStaticParams → [])
├── themes/page.tsx            # index du thésaurus : Thèmes, Institutions, Types de textes (ISR 5 min)
├── themes/[slug]/page.tsx     # documents d'un thème, sous-thèmes, pagination (dynamique : searchParams)
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
- **Collections** : `/collections/[type]` lit `searchParams`, donc rendue à la demande (`ƒ` au build) ; ne pas y remettre d'export `revalidate` (sans effet). Protection de l'API : cache de données des appels (300 s, mots-clés 3600 s). Page > dernière → état « page inexistante » ; page > 500 → 404. Après une navigation dans la page, `ResultsFocus` rend le focus au titre des résultats.
- **Anciennes URL** (`middleware.ts`) : chemins hors routes de l'app et hors fichiers statiques usuels → `resolveLegacyPath` (délai 2 s), avec la query string pour tout chemin `/index.php…` (`/index.php?option=…`, `/index.php/component/abook/book?id=N`) ; trouvé → 301/302 vers un chemin interne du même site uniquement (`lib/redirects.ts`, `safeRedirectTarget` : ni contrôle, ni barre inverse, ni `//`, ni segment `.`/`..` même encodé ; cible reconstruite depuis le chemin normalisé — mêmes règles en base, contrainte CHECK) ; réponses mémorisées 10 min par processus ; toute erreur → suite normale (404). Les anciennes URL `…/file` mènent à la fiche, jamais à l'API. Garder `APP_PREFIXES` synchronisé avec `app/` et `public/`.
- **Thèmes** (`lib/themes.ts`) : `GET /v1/library/mots-cles[?type=]` ; `documents_count` (plan 4) est facultatif. Sans comptes : thèmes affichés sans nombre, ni puces « Thèmes de la collection », ni liens vers les pages de regroupement (racines, niveaux intermédiaires), sitemap limitée à `/themes`. Avec comptes : mots-clés à 0 masqués ; puces de collection = mots-clés comptés non racines (niveaux intermédiaires compris). « Autres ouvrages » et « Généralités » (fourre-tout) restent sur `/themes` et dans les puces mais sont exclus des thèmes mis en avant et du nombre de thèmes de l'accueil. Sitemap : thèmes comptés hors racines du thésaurus ; `/themes/[slug]` en noindex au-delà de la page 1 ou sans document. Les mots-clés sont facultatifs sur l'accueil et les collections (sections masquées en cas d'échec), indispensables sur `/themes` et `/themes/[slug]` (`unwrapOrThrow`).
- **Charte de la refonte (plan 5)** : héros, bandeaux des pages intérieures (`components/layout/PageHeader.tsx`) et pied de page sur prune « deep » `#2E1C40` / « ink » `#211429` (surcharge locale de `tailwind.config.ts`), surfaces secondaires lavande « mist » `#F4F0F8`. Logo officiel `/logos/pssfp.png` (`BrandMark`). Couvertures de repli typographiques sans marque institutionnelle. Aucun chiffre en dur : indicateurs et comptes viennent de l'API.
- **Focus** : anneau `ring-pssfp-prune` + `ring-offset-2` sur fond clair (contour global `*:focus-visible` prune) ; or uniquement sur les fonds prune sombres (bandeau institutionnel, héros, bandeaux intérieurs, pied de page).
- **Formats** : prix via `format.number(prix, PRICE_FORMAT)` et tailles via `fileSizeFormat` (`lib/format.ts`) : devise et unités viennent d'Intl, jamais des messages.
- **SEO** : `/recherche` noindex + `Disallow`, exclue du sitemap ; variantes filtrées/paginées des collections noindex avec canonique nue.
- **Textes** : tout passe par `next-intl` (`messages/fr.json`), y compris `aria-label`, `alt`, `title`.

## Tests

Playwright (`tests/playwright/*.spec.ts`), verts **avec ou sans backend** (la CI ne démarre pas l'API) : les tests de données se marquent `test.skip()` si l'API est injoignable, et échouent explicitement sur un 429. Specs : `accueil`, `collections`, `recherche`, `fiche`, `layout`, `redirections`, `seo`, `themes` (utilitaires, composants sur `/dev/composants`, pages avec API), plus les tests unitaires de `lib/` (`routes`, `pagination`, `highlight`, `citation`, `api-client`, `composants`). Serveur de test : `pnpm dev --port 6002`. Les redirections utilisent celles de `LibraryDemoSeeder` (`/index.php/textes-et-lois` → `/collections/texte_loi`, `/index.php?option=com_docman&view=list&slug=memoires` → `/collections/memoire`).

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
NEXT_PUBLIC_API_URL=http://localhost:8000/v1   # obligatoire en production pour `next build` (hors CI) et `next start`
NEXT_PUBLIC_SITE_URL=https://library.pssfp.org
NEXT_PUBLIC_MAIN_SITE_URL=http://localhost:6001
NEXT_PUBLIC_FOAD_URL=https://foad.pssfp.net
```

## Sous-agent dédié

`.claude/agents/biblio-reviewer.md` — relit les diffs biblio (access_level, URLs signées, filigrane).
