# Bibliothèque — Plan 5 : refonte UX/UI éditoriale et navigation par thèmes

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** donner à `library.pssfp.org` le langage visuel d'une bibliothèque institutionnelle de référence (brief « violet institutionnel », maquette `pssfp-library-concept-violet.html`) sans toucher aux règles métier, et livrer la navigation par thèmes (plan 4, tâches 4 et 5).

**Architecture:** refonte de la couche UI de `library/` uniquement. Les appels à l'API, la logique d'accès (`DocumentAccess` vu à travers `access.reason`), les filtres GET, la recherche côté navigateur, la balise de consultation, le middleware des anciennes URL, le SEO, la sitemap et `robots` restent en place. Les nouveaux composants s'appuient sur le preset `@pssfp/ui` et sur une surcharge locale de `library/tailwind.config.ts` (aucune modification de `packages/ui`). Les thèmes consomment `GET /v1/library/mots-cles[?type=]` avec `documents_count` facultatif (absent tant que le backend du plan 4 n'est pas déployé : thème affiché sans compte, jamais d'erreur).

**Tech Stack:** Next.js 14 App Router, next-intl, Tailwind (preset `@pssfp/ui`), lucide-react, Playwright + `@axe-core/playwright` (déjà présent).

## Contraintes qui priment sur le brief

- Charte ADR-0008 (c'est la charte violette officielle demandée par le brief) : prune `#4A2E67` (titres, navigation, CTA plat), prune profonde `#3A2452` (survol, surfaces sombres du héros et du pied de page, avec des nuances plus sombres de prune), prune claire `#5C3A7E`, lavande `#A592BD` (fonds doux, filets), bleu pétrole `#0F3A4A` (rare), or `#D4AF6A` (accent décoratif rare ; or foncé `#7A5A1C` pour du texte sur fond clair), ivoire `#FAF7F2`, graphite `#3C3C3C`. Cormorant Garamond (titres), Source Sans 3 (corps), DM Sans (micro-texte). Pas de Georgia, pas des codes indicatifs du brief.
- Logo officiel `/logos/pssfp.png` (celui du site et de la candidature), jamais le pictogramme à trois arcs de la maquette ; sur fond sombre, posé sur une pastille claire (zone de protection).
- Aucune fausse fonctionnalité : ni favoris, ni modale de démonstration, ni onglets fictifs, ni chiffres en dur. Statistiques = `getStats`, comptes de collections = `by_type`, seules les collections non vides sont des liens. Pas d'icône `Sparkles`.
- Couvertures : `cover_url` réelle sinon couverture typographique (style « mini-cover » de la maquette) qui n'imite aucune couverture officielle.
- Tous les textes via `t('…')` (`library/messages/fr.json`). WCAG 2.1 AA (contraste ≥ 4,5:1, cibles ≥ 24 px, focus visible, `prefers-reduced-motion`), animations limitées à `transform`/`opacity`. Aucun débordement horizontal à 320, 390, 768, 1024, 1440 px.

---

### Task 1 : socle visuel — jetons, en-tête, tiroir, pied de page, fil d'Ariane

**Files:**
- Modify: `library/tailwind.config.ts` (nuances sombres de prune pour héros et pied de page), `library/app/globals.css`
- Modify: `library/components/layout/nav-config.ts` (Accueil · Collections · Nouveautés · Thèmes · À propos), `SiteHeader.tsx` (bandeau institutionnel + navigation + accès Recherche), `MobileDrawer.tsx`, `SiteFooter.tsx`, `Breadcrumbs.tsx` (variante sur fond sombre)
- Create: `library/components/layout/BrandMark.tsx` (logo officiel)
- Modify: `library/middleware.ts` (`/themes` dans `APP_PREFIXES`), `library/messages/fr.json`
- Test: `library/tests/playwright/layout.spec.ts`

- [ ] Tests : les cinq entrées de navigation (desktop et tiroir), « Thèmes » → `/themes`, `aria-current` sur Accueil à `/` ; logo officiel (image avec alternative) ; liens Site du PSSFP et FOAD ; en-tête collant couvert par `scroll-padding-top` ; 320 px sans débordement.
- [ ] Implémenter, `pnpm lint && pnpm typecheck && pnpm test` verts, commit `feat(biblio-front): socle visuel de la refonte (en-tete, pied de page)`.

### Task 2 : composants documentaires — couverture, carte, badge, recherche, collection

**Files:**
- Modify: `library/components/document/DocumentCover.tsx` (mini-cover), `DocumentCard.tsx` (carte éditoriale grille + ligne), `AccessBadge.tsx`
- Modify: `library/components/home/SearchForm.tsx` (variante héros sur fond sombre), `Eyebrow.tsx`
- Create: `library/components/home/CollectionCard.tsx`
- Modify: `library/app/dev/composants/page.tsx` + `fixtures.ts`
- Test: `library/tests/playwright/composants.spec.ts`

- [ ] Tests : couverture typographique sans marque institutionnelle, image réelle sinon ; carte = `article` à lien étiré, badge d'accès réel, thème principal affiché s'il existe ; carte de collection avec compte formaté ; axe sans violation grave sur `/dev/composants`.
- [ ] Implémenter, suite verte, commit `feat(biblio-front): composants documentaires de la refonte`.

### Task 3 : page d'accueil

**Files:**
- Modify: `library/app/page.tsx`, `library/components/home/HomeHero.tsx` (héros prune, recherche, suggestions, composition de couvertures réelles), `MostRead.tsx`, `LatestDocuments.tsx`
- Create: `library/components/home/StatsBar.tsx`, `CollectionsGrid.tsx` (remplace `CollectionsMosaic.tsx`), `LegalFeature.tsx`, `ThemesTeaser.tsx`
- Create: `library/lib/themes.ts` (arbre du thésaurus, comptes facultatifs, sélection des thèmes mis en avant)
- Modify: `library/lib/api/types.ts` (`MotCle.documents_count?: number`), `library/lib/api/library.ts` (`getMotsCles({ type })`)
- Test: `library/tests/playwright/accueil.spec.ts`, `library/tests/playwright/themes.spec.ts` (utilitaires)

- [ ] Tests : un seul `h1` « Le savoir public. À portée de tous. », recherche GET vers `/recherche`, indicateurs issus de `getStats`, tuiles des seules collections non vides avec compte de `by_type`, nouveautés ≤ 6, bloc juridique vers `/collections/texte_loi`, thèmes vers `/themes/{slug}` (masqués si l'API des mots-clés échoue), axe, 320 px ; utilitaires de `lib/themes.ts` (compte absent toléré).
- [ ] Captures pleine page 1440 et 390 px (API de production en lecture seule, balise de consultation bloquée).
- [ ] Suite verte, commit `feat(biblio-front): nouvelle page d'accueil`.

### Task 4 : collections et recherche

**Files:**
- Create: `library/components/layout/PageHeader.tsx` (bandeau des pages intérieures : fil d'Ariane, sur-titre, titre, compte, recherche persistante)
- Modify: `library/app/collections/[type]/page.tsx`, `library/app/recherche/page.tsx`, `library/components/catalogue/*` (FilterPanel, ActiveFilters, SortSelect, Pagination, StateMessage), `library/components/search/*` (FacetList, SearchExperience, SearchStates)
- Test: `library/tests/playwright/collections.spec.ts`, `library/tests/playwright/recherche.spec.ts`

- [ ] Tests existants inchangés sur le comportement (filtres, tri, pagination, puces, focus, squelette, 429) ; contraste des contours ≥ 3:1 ; 320 px.
- [ ] Suite verte, commit `feat(biblio-front): collections et recherche a la nouvelle charte`.

### Task 5 : fiche document

**Files:**
- Modify: `library/app/documents/[slug]/page.tsx`, `library/components/document/AccessAction.tsx`, `CitationBox.tsx`
- Test: `library/tests/playwright/fiche.spec.ts`

- [ ] Tests existants (accès selon `access.reason`, citation APA + copie, consultations, documents liés, JSON-LD, balise) verts ; mots-clés menant au thème ; axe.
- [ ] Suite verte, commit `feat(biblio-front): fiche document a la nouvelle charte`.

### Task 6 : navigation par thèmes (plan 4, tâches 4 et 5)

**Files:**
- Modify: `library/lib/routes.ts` (`themesHref`, `themeHref(slug, page?)`), `library/app/sitemap.ts`, `library/messages/fr.json`
- Create: `library/app/themes/page.tsx`, `library/app/themes/[slug]/page.tsx`, `library/components/themes/ThemeIndex.tsx`, `library/components/catalogue/CollectionThemes.tsx`
- Modify: `library/app/collections/[type]/page.tsx` (puces « Thèmes de la collection »), `library/app/dev/composants/*`
- Test: `library/tests/playwright/themes.spec.ts`, `collections.spec.ts`, `seo.spec.ts`

Spécification : celle du plan 4 (tâches 4 et 5), avec la tolérance suivante tant que l'API ne renvoie pas `documents_count` : thème affiché sans compte (jamais masqué ni « 0 »), puces de collection triées par libellé, sitemap limitée à `/themes`.

- [ ] Tests : `/themes` (trois groupes, comptes, niveau intermédiaire des institutions), page d'un thème (fil d'Ariane, compte, sous-thèmes, pagination, 404 si slug inconnu, canonique nue, `noindex` au-delà de la page 1), puces de collection (lien `mot_cle`, `aria-current`, retrait, clavier), sitemap avec `/themes`. Sans API : composants testés sur `/dev/composants`, tests de pages ignorés.
- [ ] Suite verte, commit `feat(biblio-front): navigation par themes`.

### Task 7 : pages annexes et vérification finale

**Files:**
- Modify: `library/app/a-propos/page.tsx`, `library/app/not-found.tsx`, `library/components/catalogue/ServiceUnavailable.tsx`, `library/CLAUDE.md` (routes `/themes`)
- Test: suite complète

- [ ] `pnpm lint`, `pnpm typecheck`, `pnpm build`, `pnpm test` verts ; axe sur accueil, collection, fiche, thèmes ; revue manuelle des contrastes (or et lavande).
- [ ] Captures 1440 et 390 px : accueil, collection, recherche, fiche, `/themes`.
- [ ] Commit `docs(biblio): routes themes et refonte dans le guide de la bibliotheque`.
