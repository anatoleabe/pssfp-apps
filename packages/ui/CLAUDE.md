# /packages/ui — design system @pssfp/ui partagé

> Lu automatiquement par Claude Code à chaque session ouverte dans ce dossier.

## Mission

Package interne TypeScript publié en workspace pnpm sous le nom `@pssfp/ui`, consommé par les 3 apps Next.js (`frontend`, `library`, `candidature`). Centralise le design system, les composants communs (header, footer, design tokens) et évite la duplication.

## Périmètre

**Inclus** :

- Design tokens (couleurs CDC §10.1, typographies, espacements, breakpoints).
- Composants communs : `Logo`, `Header` (variantes par app), `Footer`, `CookieBanner`, `Breadcrumb`, `Pagination`, `Spinner`, `Skeleton`, `EmptyState`, `ErrorBoundary`.
- Composants atomiques shadcn-style adaptés à la charte PSSFP : `Button`, `Card`, `Badge`, `Input`, `Select`, `Dialog`, `Sheet`, `Toast`, `Form` (helpers).
- Hooks utilitaires : `useDebounce`, `useMediaQuery`, `useLockBodyScroll`, `useClickOutside`.
- Helpers de formattage : `formatDate`, `formatCurrency` (FCFA), `formatNumber`.
- Icônes wrapper sur Lucide React.

**Non inclus** :

- Composants métier spécifiques à un domaine (ex: `DocumentCard` reste dans `library/components/`, `CandidatureWizard` reste dans `candidature/components/`).
- Logique d'auth, routing — propre à chaque app.
- Schémas zod, types métier — propres à chaque app.

## Structure

```
packages/ui/
├── package.json                # name: "@pssfp/ui"
├── tsconfig.json
├── tailwind.config.ts          # config partagée
├── src/
│   ├── index.ts                # barrel export
│   ├── tokens/
│   │   ├── colors.ts           # palette CDC
│   │   ├── typography.ts
│   │   └── spacing.ts
│   ├── components/
│   │   ├── Button/
│   │   ├── Card/
│   │   ├── Header/
│   │   │   ├── HeaderInstitutional.tsx
│   │   │   ├── HeaderLibrary.tsx
│   │   │   └── HeaderCandidature.tsx
│   │   ├── Footer/
│   │   ├── CookieBanner/
│   │   └── ...
│   ├── hooks/
│   ├── utils/
│   └── icons/
└── tests/
```

## Conventions

- **TypeScript strict**. Pas de `any` toléré.
- **Pas de logique métier**, uniquement présentation et utilitaires UI.
- **Tous les composants typés** avec `interface` exposée pour les props.
- **Variants** via `class-variance-authority` (cva) pour les composants atomiques.
- **Forwarded refs** pour les composants potentiellement utilisés dans des libs tierces.
- **Aucune dépendance directe** à React Server Components — tous les composants sont compatibles RSC sauf indication explicite (`'use client'`).

## Design tokens (charte 2026 — ADR-0008)

```typescript
export const colors = {
  primary: '#4A2E67',          // Prune institutionnelle
  primaryHover: '#3A2452',     // Prune profonde
  primaryLight: '#5C3A7E',     // Prune claire
  secondary: '#0F3A4A',        // Bleu pétrole — 2e accent autorité
  secondaryHover: '#082A37',
  tertiary: '#A592BD',         // Lavande grisée
  surface: '#A592BD',
  accent: '#D4AF6A',           // Or champagne
  accentHover: '#E5C788',
  background: '#FFFFFF',
  ivoire: '#FAF7F2',
  text: '#3C3C3C',             // Graphite
  textStrong: '#1A1A1A',
  textMuted: '#6B6B6B',
  surfaceAlt: '#F4F0EA',
  // Sémantiques
  success: '#2E7D32',
  warning: '#F9A825',
  error: '#C62828',
  info: '#1565C0',
} as const;
```

Typographies (ADR-0008) :

```typescript
export const fonts = {
  heading: 'Cormorant Garamond, Georgia, serif',
  body: 'Source Sans 3, system-ui, sans-serif',
  ui: 'DM Sans, sans-serif',
} as const;
```

## Build et publication

Pas de build dist — le package est consommé en source via TypeScript path mapping côté apps. Chaque app a dans son `tsconfig.json` :

```json
{
  "compilerOptions": {
    "paths": {
      "@pssfp/ui": ["../packages/ui/src"],
      "@pssfp/ui/*": ["../packages/ui/src/*"]
    }
  }
}
```

Pas de transpilation séparée. Les apps Next.js compilent directement les sources TypeScript du package via `transpilePackages: ['@pssfp/ui']` dans `next.config.js`.

## Tests

- **Storybook** optionnel (à décider) — sinon tests visuels via Playwright sur une route `/storybook` interne.
- **Tests unitaires Vitest** sur les hooks et utils.
- **Tests a11y** via `@axe-core/playwright` sur les composants visuels.

## Versioning

Pas de versioning sémantique externe — le package est interne au mono-repo. Les changements breaking nécessitent juste de mettre à jour les imports dans les 3 apps consommatrices.

## Dépendances

- `react@^18` (peer)
- `react-dom@^18` (peer)
- `tailwindcss@^3` (peer)
- `class-variance-authority`
- `clsx`
- `tailwind-merge`
- `lucide-react`
- `@radix-ui/react-*` (primitives shadcn)
