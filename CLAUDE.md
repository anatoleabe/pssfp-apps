# pssfp.net — mono-repo

> Ce fichier est lu **automatiquement** par Claude Code à chaque session ouverte à la racine du repo. C'est la mémoire longue du projet.

## Mission

Refonte du site institutionnel PSSFP, de la bibliothèque virtuelle, et du formulaire de candidature en ligne.

**Référence contractuelle** :

- `docs/CDC_Site_Web_PSSFP_v5_2026.docx` — CDC v5 du site web (à copier dans le repo si pas déjà présent).
- `docs/CDC_Bibliotheque_Virtuelle_PSSFP_v2.docx` — CDC v2 de la biblio (idem).
- `PLAYBOOK_Claude_Code_PSSFP.md` — méthodologie Plan → Act → Verify.

**Chef de Projet** : M. ABE ETOUMOU Anatole — Chef USI (`anatoleabe@gmail.com`).

## Stack technique (gelée par ADR-0001)

- **Frontend public** : Next.js 14 (App Router, TS strict, Tailwind, shadcn/ui) — `/frontend` → `pssfp.net`.
- **Bibliothèque** : Next.js 14 — `/library` → `bibliotheque.pssfp.net`.
- **Candidature** : Next.js 14 — `/candidature` → `candidature.pssfp.net`.
- **API + Admin** : Laravel 11 (PHP 8.3) + Filament 3 — `/backend` → `api.pssfp.net` (admin sur `/admin`).
- **Package partagé** : `@pssfp/ui` dans `/packages/ui` — header, footer, design system, composants communs aux 3 apps Next.js.
- **BDD** : PostgreSQL 16 (ADR-0002) · **Cache** : Redis 7 · **Recherche** : Meilisearch (ADR-0003) · **Stockage** : MinIO local + Scaleway backup.
- **Auth** : Sanctum + tokens scoped (ADR-0005) ; sessions Laravel pour Filament admin avec 2FA TOTP obligatoire.
- **i18n** : FR par défaut, structure prête EN/AR (ADR-0006).

## Règles de travail

1. **JAMAIS de code sans plan écrit validé d'abord.** Toujours répondre par un plan avant d'agir.
2. Commits en **Conventional Commits**. Branche par feature : `feat/m1-page-accueil`, `fix/biblio-pagination`, `docs/adr-0007`, etc.
3. **Tests obligatoires** : Pest côté Laravel, Playwright côté Next.js. Pas de merge sans tests verts en CI.
4. **Accessibilité WCAG 2.1 AA** non négociable. Contraste ≥ 4.5:1, navigation clavier complète, alts sur toutes les images.
5. **Score Lighthouse ≥ 90** sur Performance / Accessibilité / SEO / Best Practices pour les pages publiques (≥ 85 pour la biblio et la candidature).
6. **Tous les textes publics en français** (source). Balisage `t('key')` obligatoire (next-intl) — JAMAIS de texte en dur dans le JSX.
7. **Aucune clé API ni secret en clair** : `.env` uniquement, jamais commit. `.env.example` versionné sans valeurs sensibles.
8. **Données de test (seeders)** ne doivent pas contenir de vraies données nominatives.
9. **PostgreSQL** : pas de SQL brut sauf justification ADR. Eloquent + Scopes nommés. Eager loading systématique pour éviter N+1.
10. **API publique** : pas d'`id` interne BIGSERIAL exposé — UUID pour tout ce qui est routé publiquement.

## Charte graphique (CDC §10.1)

- **Violet institutionnel** : `#6B2FA0` — titres, CTA principaux, navigation.
- **Violet moyen** : `#9B59B6` — hover, liens actifs.
- **Lavande** : `#EDE7F6` — fonds doux.
- **Or** : `#C9A227` — badges, accents partenaires.
- **Texte** : `#333333` sur fond `#FFFFFF` (contraste WCAG AA).
- **Polices** : Playfair Display Bold (titres H1/H2), Inter Regular 16px+ (corps), DM Sans Medium (UI).

## Arborescence publique

8 rubriques sur `pssfp.net` : Accueil, Le PSSFP, Formations, Candidature (lien vers `candidature.pssfp.net`), Vie Académique, Actualités, Bibliothèque (lien vers `bibliotheque.pssfp.net`), Contact.

Règle des 3 clics maximum. Sticky header, breadcrumb, footer complet.

## Personae prioritaires

1. **Candidat potentiel** — CTA Candidature visible partout, parcours optimisé.
2. **Auditeur actif** — accès rapide FOAD + Bibliothèque.
3. **Partenaire / administration** — formations continues, contact.
4. **Enseignant** — calendrier + dépôt biblio.
5. **Journaliste/Décideur** — gouvernance, conventions, conformité CAMES.

## Intégrations externes

- **Moodle FOAD** : `foad.pssfp.net` — simple lien (SSO = Phase II).
- **Facebook** : flux `@pssfpcameroun` via Page Plugin officiel après consentement RGPD.
- **Email institutionnel** : `@pssfp.net` (creawebhosting SMTP `mail.creawebhosting.org:465`).
- **Google Maps** : embed pour Campus Messa, lazy avec consentement.
- **Cloudflare Turnstile** : captcha gratuit RGPD-friendly pour le formulaire de contact et l'inscription candidat.

## Modules en cours — cf. `docs/specs/`

Phase I : Module 1 (site institutionnel), Module 2 (actualités), Module 3 (biblio), Module 5 (candidatures — rapatrié de Phase II), Module 6 (CMS Filament).
Phase II : Module 4 (espace étudiant SSO Moodle), Module Paiement biblio.

## Hors périmètre Phase I (rappel)

- Espace étudiant sécurisé avec SSO Moodle.
- Module de paiement biblio multi-canal.
- Refonte de la plateforme Moodle FOAD elle-même.

## Workflow Plan → Act → Verify (cf. PLAYBOOK §7)

1. **Plan** — toujours produire un plan numéroté avant tout code. Refuser tout plan > 8 étapes : demander un découpage.
2. **Act** — exécuter étape par étape, diff relu, tests, commit Conventional Commits.
3. **Verify** — `make test`, `make lighthouse`, sous-agent approprié (`/cames-check`, `/a11y-reviewer`, etc.), mise à jour spec si découverte.

## Commandes utiles

- `make dev` — démarre docker (postgres, redis, meili, mailpit) + frontend + library + candidature + backend.
- `make test` — Pest + Playwright + lint sur les 4 apps.
- `make lighthouse` — audit Lighthouse sur routes critiques.
- `make cames` — vérifie la checklist CAMES automatisée.
- `make build` — build des 3 apps Next.js + assets Laravel.
- `make deploy` — déploiement VPS Contabo (`main` uniquement, après CI verte).

## Points d'attention récurrents

- **Cookies cross-app** : `SESSION_DOMAIN=.pssfp.net` côté Laravel pour permettre auth partagée entre `bibliotheque.pssfp.net` et `candidature.pssfp.net`. Nécessite CORS `withCredentials: true` côté Next.js.
- **MinIO URL signées** : 30 min de validité, jamais d'URL publique pour les fichiers privés (cours biblio, pièces candidatures).
- **Filigrane PDF** sur téléchargements documents biblio restreints (cf. `docs/specs/module-3-bibliotheque.md` §12).
- **Activity Log** Spatie sur toutes les Resources Filament sensibles.
- **Backups** : `laravel-backup` quotidien vers MinIO local + Scaleway, rétention 30j.

## Sous-agents disponibles

Voir `.claude/agents/` :

- `cames-reviewer` — vérifie conformité CAMES (12 exigences).
- `a11y-reviewer` — vérifie accessibilité WCAG 2.1 AA.
- `security-reviewer` — relit les diffs sécurité/auth.
- `filament-reviewer` — relit les Resources Filament.
- `biblio-reviewer` — relit les diffs biblio (access_level, URLs signées).
- `candidature-reviewer` — relit les diffs module candidature (PII, ownership).
- `i18n-reviewer` — vérifie absence de texte en dur dans le JSX.

## Slash-commands disponibles

Voir `.claude/commands/` :

- `/plan-module` — démarre une session de dev sur un module avec lecture spec.
- `/verify-lighthouse` — lance audit Lighthouse sur les routes critiques.
- `/cames-check` — vérifie la checklist CAMES.
- `/ship` — checklist de pré-PR (tests, build, diff, doc, ADR si besoin).
