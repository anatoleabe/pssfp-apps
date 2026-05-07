# ADR-0001 — Stack Next.js 14 + Laravel 11 découplée

**Statut** : Accepté
**Date** : 2026-05-05
**Décideur** : M. ABE ETOUMOU Anatole — Chef USI, Chef de Projet opérationnel
**Référence CDC** : CDC-PSSFP-WEB-2026-005 v5.0 §9.1 « Stack technologique »

## Contexte

Le PSSFP refond son site institutionnel (pssfp.net), sa bibliothèque virtuelle (bibliotheque.pssfp.net), et — par décision du 5 mai 2026 — rapatrie en Phase I la réécriture du module de candidature en ligne (candidature.pssfp.net). À cela s'ajoute l'interface d'administration unique pour l'équipe USI.

Le projet doit satisfaire simultanément cinq exigences difficiles à concilier dans un seul outil :

1. **SEO et performance** — score Lighthouse ≥ 90 sur les 4 dimensions, temps de chargement < 2s sur connexion 3G camerounaise (CDC §9.3). Indexation CAMES et Google Scholar pour la valorisation académique.
2. **Mobile-first** — ~70% du trafic vient des smartphones au Cameroun (CDC §10.3).
3. **Administration sans-code** — l'USI doit gérer pages, articles, formations, biblio sans intervention développeur (CDC §15.2).
4. **Évolutivité** — préparation de la transition ISFP Phase II (espace étudiant, SSO Moodle).
5. **Maintenabilité par une équipe USI réduite** — pool de compétences PHP/Laravel disponible localement, formation possible sur écosystème React/Next.

Quatre architectures candidates ont été examinées lors du cadrage (Phase 1 du planning CDC).

## Décision

Architecture **découplée** en mono-repo :

- **Frontend public** : Next.js 14 (App Router, TypeScript strict, Tailwind CSS, shadcn/ui) — déployé sur `pssfp.net`.
- **Frontend bibliothèque** : Next.js 14 (même conventions) — déployé sur `bibliotheque.pssfp.net`. Application séparée pour permettre déploiements indépendants et différenciation UX.
- **Frontend candidature** : Next.js 14 — déployé sur `candidature.pssfp.net` (rapatrié Phase I, cf. ADR future).
- **Backend API + Admin** : Laravel 11 (PHP 8.3) avec Filament 3 pour l'administration, Sanctum pour l'authentification API — déployé sur `api.pssfp.net`, admin accessible sur `api.pssfp.net/admin`.
- **Communication** : API REST JSON versionnée (`/v1/`). Pas de GraphQL, pas de tRPC — REST suffit pour ce niveau de complexité.
- **Stratégie de rendu Next.js** : SSG par défaut (pages institutionnelles), ISR pour contenus éditoriaux (revalidate 5 min actualités, 1 h formations), SSR pour pages dynamiques personnalisées (mon-espace biblio, recherche).
- **Package interne partagé** : `@pssfp/ui` dans `/packages/ui/` du mono-repo, regroupant header, footer, design system, composants communs aux trois apps Next.js. Évite la duplication.

## Conséquences

### Positives

Cette architecture maximise les performances perçues : Next.js sert des pages pré-rendues quasi-statiques au navigateur, CDN-isables, ce qui rend atteignable le score Lighthouse cible. Le découplage permet à l'API Laravel d'être consommée à terme par d'autres clients (mobile natif, outils internes, partenaires). Filament côté admin offre 80% du CMS gratuitement (formulaires, tables, relations, médiathèque), ce qui libère du temps de dev pour les modules métier. La séparation en trois apps frontend permet des déploiements indépendants : un bug en biblio ne fait pas tomber le site institutionnel.

### Négatives ou trade-offs

Deux écosystèmes (PHP et JavaScript) à maintenir, avec deux pipelines de tests (Pest côté Laravel, Playwright côté Next.js), deux pipelines de build, deux jeux de dépendances à mettre à jour. Coût de maintenance récurrent estimé à 1 jour-USI par mois, contre ~0,5 jour pour un monolithe. Authentification cross-app à orchestrer côté Sanctum (cf. ADR-0005). Risque de duplication entre les trois apps Next.js — atténué par le package `@pssfp/ui`.

### Neutres ou à surveiller

L'évolution future vers le SSO Moodle (Phase II) et les modules métier complexes (notes, relevés) implique de garder l'API Laravel propre et bien versionnée dès le départ. Surveiller que le team USI monte effectivement en compétence sur React/Next — sinon, prévoir formation externe ou recrutement.

## Alternatives envisagées

**Alternative A — Monolithe Laravel + Blade.** Plus simple pour l'équipe USI, un seul stack à maîtriser. Rejetée : pas de SSG natif, perf mobile difficile à atteindre, JavaScript progressif fragile, mauvaise séparation API/présentation pour la Phase II.

**Alternative B — SPA pure (React + API Laravel).** Frontend 100% client-side. Rejetée : SEO catastrophique pour une institution publique qui doit être indexée par CAMES et Google Scholar, premier rendu lent sur 3G camerounaise.

**Alternative C — Headless CMS SaaS (Strapi, Contentful, Sanity) + Next.js.** Rejetée : externalisation des données institutionnelles vers un fournisseur tiers (problème de souveraineté pour une institution publique camerounaise), coût récurrent, interface admin moins riche que Filament pour les workflows métier (validation biblio, candidatures).

**Alternative D — WordPress + headless mode.** Rejetée : sécurité historiquement problématique sur WordPress, écosystème de plugins tiers à risque, gestion fine des permissions et workflows moins propre que Filament + Spatie.

## Références

- CDC v5 §9.1 « Stack technologique » et §9.3 « Exigences de performance »
- CDC bibliothèque v2.0 §I.1 « Stack technologique »
- Playbook Claude Code PSSFP §3 « Stack technique validée »
- Next.js App Router documentation (https://nextjs.org/docs/app)
- Filament 3 documentation (https://filamentphp.com/docs/3.x)
