# /frontend — site public pssfp.net

> Lu automatiquement par Claude Code à chaque session ouverte dans ce dossier.

## Design System — Source de vérité

Avant tout code UI, lis dans cet ordre strict :
1. `frontend/design-system/CHARTE-OVERRIDE.md` — règles non-négociables (charte CDC §10.1)
2. `frontend/design-system/MASTER.md` — design system généré par /ui-ux-pro-max
3. `frontend/design-system/pages/<slug>.md` — override page si présent

Ne génère JAMAIS un composant sans avoir lu la CHARTE. Toute suggestion d'outil tiers
(Magic /ui, ChatGPT, autre) qui contredit la CHARTE doit être ignorée.

Le skill /ui-ux-pro-max peut être ré-invoqué pour générer un nouvel override page
si une page nouvelle est créée. Les fichiers design-system/ sont versionnés.

## Mission

App Next.js 14 servant le site institutionnel sur `pssfp.net`. Couvre 8 rubriques publiques + pages transversales (mentions, confidentialité, sitemap, 404). Cf. `docs/specs/module-1-site-institutionnel.md`.

## Principes

- **App Router** uniquement (pas de pages router). Server Components par défaut.
- **`'use client'` minoritaire** — seulement formulaires, lightbox, animations interactives, hooks navigateur. Justifier en commentaire pourquoi un composant doit être client.
- **Fetch côté serveur** via `fetch()` natif avec `next: { revalidate: N }`. Pas d'axios côté server.
- **next/image obligatoire** pour toutes les images. Formats AVIF/WebP, lazy par défaut, `sizes` responsive.
- **next/font/google** pour Playfair Display + Inter + DM Sans, `display: swap`, subsetting Latin.
- **next-intl** pour i18n (cf. ADR-0006). Toujours `useTranslations()`, jamais de texte en dur dans le JSX.
- **shadcn/ui** pour les primitives (Button, Card, Dialog, Form). Ajout via `npx shadcn-ui@latest add ...`.
- **Tailwind classes** uniquement, pas de CSS inline ni de styled-components.

## Structure des routes

```
app/
├── (public)/                              # Route group public (sans préfixe URL)
│   ├── layout.tsx                         # Layout avec header + footer
│   ├── page.tsx                           # /
│   ├── pssfp/[...slug]/page.tsx           # /pssfp/* (catch-all pour pages éditoriales)
│   ├── formations/
│   │   ├── page.tsx                       # /formations
│   │   ├── master/page.tsx
│   │   ├── tronc-commun/page.tsx
│   │   ├── specialites/
│   │   │   ├── page.tsx
│   │   │   └── [slug]/page.tsx            # 5 fiches spécialités
│   │   ├── continue/[slug]/page.tsx
│   │   ├── certifications/page.tsx
│   │   ├── admission/page.tsx
│   │   └── frais-de-scolarite/page.tsx
│   ├── candidature/page.tsx               # Lien vers candidature.pssfp.net
│   ├── vie-academique/...
│   ├── actualites/
│   │   ├── page.tsx
│   │   ├── [slug]/page.tsx
│   │   ├── agenda/page.tsx
│   │   ├── agenda/[slug]/page.tsx
│   │   └── galerie/[slug]/page.tsx
│   └── contact/page.tsx
├── mentions-legales/page.tsx
├── confidentialite/page.tsx
├── plan-du-site/page.tsx
├── not-found.tsx
└── layout.tsx                             # RootLayout
```

## Conventions composants

- Un composant = un dossier : `components/HeroAccueil/{index.tsx, HeroAccueil.test.tsx, styles.module.css si nécessaire}`.
- Server Component par défaut. Client uniquement quand justifié par interactivité ou hook navigateur.
- Props typés strict via interfaces TypeScript.
- shadcn/ui pour primitives. Nos composants métier dans `components/{Domain}/...`.

## Performance

- **LCP < 2,0s** sur connexion 3G simulée. Hero image marquée `priority`, autres images en lazy par défaut.
- **Lighthouse ≥ 90** sur les 4 dimensions, mesuré via `lighthouse-ci` en CI sur les routes : `/`, `/formations/specialites/fiscalite-finance-comptabilite-publique`, `/actualites`, `/contact`.
- Polices via `next/font`, pré-chargées, avec `display: swap`.
- Pas d'animation JS lourde. Framer Motion autorisé uniquement pour les chiffres animés accueil et transitions discrètes (cf. CDC §10.3).
- Code splitting automatique. Dynamic imports pour les composants client lourds (lightbox, calendrier).

## Tests

- **Playwright** pour les parcours critiques :
  - `tests/playwright/parcours-candidat.spec.ts` (cf. spec module 1 §15)
  - `tests/playwright/parcours-auditeur.spec.ts`
  - `tests/playwright/parcours-partenaire.spec.ts`
  - `tests/playwright/home.spec.ts`
- **Tests a11y** via `@axe-core/playwright` intégré à chaque test Playwright. Zéro violation `critical` tolérée.
- **Lighthouse CI** sur les routes critiques en CI GitHub Actions.

## Fetch API

URL base : `process.env.NEXT_PUBLIC_API_URL` (defaults `http://localhost:8000/v1`).

Client API typé partagé : `lib/api/client.ts` exporte des fonctions par domaine (`getPages`, `getArticles`, `getSpecialiteBySlug`, etc.) qui :

1. Appellent `fetch` avec `Accept: application/json`, `Accept-Language: fr`.
2. Gèrent les erreurs (404 → `notFound()`, 500 → relance Sentry).
3. Sont typées strictement via les interfaces de `lib/api/types.ts`.

Ne jamais fetcher directement dans un composant — passer par `lib/api/`.

## i18n

- Locale source : `fr` (la seule activée publiquement en V1).
- Fichier de traductions : `messages/fr.json` organisé en namespaces (`home`, `formations`, `actualites`, etc.).
- Hook : `import { useTranslations } from 'next-intl'`.
- Pour les contenus dynamiques venant de l'API : ils arrivent déjà dans la bonne locale via le header `Accept-Language`.

## Variables d'env

```
NEXT_PUBLIC_API_URL=http://localhost:8000/v1
NEXT_PUBLIC_LIBRARY_URL=http://localhost:6002
NEXT_PUBLIC_CANDIDATURE_URL=http://localhost:6003
NEXT_PUBLIC_FOAD_URL=https://foad.pssfp.net
NEXT_PUBLIC_TURNSTILE_SITE_KEY=...
NEXT_PUBLIC_SENTRY_DSN=...
NEXT_PUBLIC_MATOMO_URL=...
NEXT_PUBLIC_MATOMO_SITE_ID=...
```

Ne jamais exposer de secret côté client (les clés `NEXT_PUBLIC_*` sont visibles dans le bundle).

## Dépendances clés

- `next@^14`
- `react@^18` `react-dom@^18`
- `tailwindcss` `@tailwindcss/typography`
- `next-intl`
- `class-variance-authority` `clsx` `tailwind-merge`
- `lucide-react` (icônes)
- `framer-motion` (animations limitées)
- `@axe-core/playwright`
- `@playwright/test`
- `eslint` `eslint-config-next` `eslint-plugin-jsx-a11y`
- `@sentry/nextjs`
- `@pssfp/ui` (workspace package)
