# /library — bibliothèque virtuelle bibliotheque.pssfp.net

> Lu automatiquement par Claude Code à chaque session ouverte dans ce dossier.

## Mission

App Next.js 14 servant la bibliothèque virtuelle sur `bibliotheque.pssfp.net`. Catalogue documentaire avec recherche multicritères Meilisearch, accès différencié par rôle, espace personnel auditeur. Cf. `docs/specs/module-3-bibliotheque.md` et CDC bibliothèque v2.0.

## Principes spécifiques biblio

- **Recherche < 500 ms** exigence contractuelle (CDC v2.0 §K.2). Toute requête de recherche passe par Meilisearch.
- **Accès différencié strict** : `public` / `auditor` / `teacher` / `librarian` (cf. data-model.md §8). Un document `access_level != 'public'` n'apparaît jamais dans une réponse non authentifiée — vérifier côté API.
- **URLs signées MinIO** (validité 30 min) pour tout téléchargement de PDF restreint. Jamais d'URL publique pour ces fichiers.
- **Filigrane PDF dynamique** sur téléchargements depuis `/cours` (cf. spec §12). Côté backend Laravel, mais le frontend doit afficher correctement les téléchargements en cours.

## Structure des routes

```
app/
├── layout.tsx                    # Header biblio + footer
├── page.tsx                      # / : accueil avec recherche proéminente
├── theses/
│   ├── page.tsx
│   └── [uuid]/page.tsx
├── articles/[uuid]/page.tsx
├── legislation/[uuid]/page.tsx
├── cours/                        # Auth requise
│   ├── page.tsx
│   └── [uuid]/page.tsx
├── conferences/[uuid]/page.tsx
├── recherche/page.tsx            # SSR — Meilisearch
├── mon-espace/                   # Auth requise
│   ├── page.tsx
│   ├── favoris/page.tsx
│   └── historique/page.tsx
├── login/page.tsx
└── not-found.tsx
```

## Auth côté frontend

Sanctum tokens scoped (cf. ADR-0005). Cookie httpOnly émis par Laravel sur `Domain=.pssfp.net`. Côté Next.js :

- Helper `lib/auth/getCurrentUser.ts` Server Component qui lit le cookie et appelle `/v1/auth/me`.
- Helper `lib/auth/requireAuth.tsx` qui redirige vers `/login?redirect=...` si non authentifié.
- Pages `/cours/*`, `/mon-espace/*` : `requireAuth` obligatoire.

## Recherche Meilisearch

- **Endpoint** : `GET /v1/library/search?q=...&filters[]=...` côté API Laravel (qui proxie Meilisearch).
- **Pas de Meilisearch direct depuis le frontend** — toujours via l'API Laravel pour appliquer les politiques d'accès.
- **Facettes dynamiques** : retournées par l'API dans `meta.facets` (cf. api-contract.md §8).
- **Highlight** des termes trouvés dans les résultats (`<mark>` HTML).
- **Auto-complétion mots-clés** via `GET /v1/library/keywords?prefix=...` (debounce 300ms côté UI).

## Citations bibliographiques

- Endpoint `GET /v1/library/citations/{uuid}?format=apa|mla|chicago|bibtex`.
- Composant `<CitationBox>` avec onglets et bouton Copier.

## Composants spécifiques biblio

- `LibraryHeader` — header biblio avec barre de recherche centrale.
- `SearchBar` (client) — barre avec autocomplétion.
- `DocumentCard` (server) — card document.
- `DocumentBadgeType` — badge couleur par type.
- `FilterSidebar` (client) — filtres multicritères.
- `CitationBox` (client) — boîte citation avec onglets.
- `FavoriteButton` (client) — toggle favori.

## Tests

Playwright sur :

- `tests/playwright/search.spec.ts` — recherche avec filtres + facettes.
- `tests/playwright/access-levels.spec.ts` — vérifie qu'un user public ne voit PAS de document `auditor` dans la liste.
- `tests/playwright/download-public.spec.ts` — téléchargement document public.
- `tests/playwright/download-restricted.spec.ts` — téléchargement document restreint (auth requise).
- `tests/playwright/favorites.spec.ts` — ajout/retrait favori.

## Performance cible

Lighthouse ≥ 85 sur les 4 dimensions. La biblio est plus interactive que le site institutionnel (formulaires, recherche avec sidebar lourde) — la cible est légèrement assouplie par le CDC v2.0 §K.2.

## Variables d'env

```
NEXT_PUBLIC_API_URL=http://localhost:8000/v1
NEXT_PUBLIC_MAIN_SITE_URL=http://localhost:6000
NEXT_PUBLIC_TURNSTILE_SITE_KEY=...
NEXT_PUBLIC_SENTRY_DSN=...
```

## Sous-agent dédié

`.claude/agents/biblio-reviewer.md` — relit les diffs biblio (access_level, URLs signées, filigrane).
