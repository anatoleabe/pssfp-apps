# /backend — API Laravel + Filament admin

> Lu automatiquement par Claude Code à chaque session ouverte dans ce dossier.

## Mission

API REST sur `api.pssfp.net/v1` consommée par les 3 apps Next.js, plus le panneau admin Filament 3 sur `api.pssfp.net/admin`. Couvre tous les endpoints publics et l'administration. Cf. `docs/api-contract.md`, `docs/data-model.md`, `docs/specs/module-6-cms.md`.

## Principes

- **Architecture** : Controllers minces, **Services pour la logique métier**, **Models** pour les règles BDD/Eloquent, **FormRequest** pour la validation, **JsonResource** pour la sérialisation API.
- **Jamais de SQL brut** sauf justification ADR. Eloquent + Scopes nommés.
- **Pas de N+1** — eager loading systématique via `with()` ou `load()`.
- **UUIDs** sur les modèles exposés publiquement (`pages`, `articles`, `documents`, `candidatures`, etc.). `id` BIGSERIAL strictement interne.
- **Champs traduisibles** en JSONB via `spatie/laravel-translatable`.
- **Soft deletes** sur entités éditoriales et métier.

## API publique `/v1/*`

Base URL prod : `https://api.pssfp.net/v1` — Base URL dev : `http://localhost:8000/v1`.

Cf. `docs/api-contract.md` pour la liste exhaustive des 62 endpoints. Routes principales :

- `POST /v1/auth/*` — register, login, logout, password, 2FA, email verification.
- `GET /v1/pages/*`, `/articles/*`, `/menu`.
- `GET /v1/formations/*` — specialites, tronc-commun, continues, certifications.
- `GET /v1/promotions`, `/enseignants`, `/evenements`, `/partenaires`.
- `GET|POST /v1/library/*` — documents, search, favorites, citations, downloads.
- `GET|POST /v1/applications/*` — campaigns, profile, candidatures, documents, decisions.
- `POST /v1/contact`.
- `GET /v1/settings`, `/health`, `/sitemap.xml`.

## Filament 3 — admin

URL : `/admin`. Sessions Laravel natives + 2FA TOTP **obligatoire** pour rôles `admin`, `editor`, `librarian`, `admission_committee`.

Resources principales (cf. spec module 6 §3) : `PageResource`, `ArticleResource`, `CategoryResource`, `TagResource`, `SpecialiteResource`, `UniteEnseignementResource`, `FormationContinueResource`, `CertificationResource`, `PromotionResource`, `EnseignantResource`, `EvenementResource`, `PartenaireResource`, `DocumentResource`, `MotCleResource`, `DocumentDownloadResource`, `CampagneCandidatureResource`, `CandidatureResource`, `CandidateProfileResource`, `UserResource`, `RoleResource`, `ContactMessageResource`, `RedirectResource`.

Rôles Spatie (`spatie/laravel-permission`) : `admin`, `editor`, `librarian`, `admission_committee`, `teacher`, `auditor`. Matrice de permissions seedée via `RolePermissionSeeder`.

**Activity Log** (`spatie/laravel-activitylog`) sur toutes les Resources stratégiques avec `logOnlyDirty()`.

## Règles BDD

- **PostgreSQL 16** (cf. ADR-0002).
- **Migrations toujours réversibles** — `down()` complet et testé.
- **Tables** : snake_case pluriel.
- **Pivots** : alphabétique singulier (`article_tag`, `enseignant_specialite`).
- **Soft deletes** sur : `pages`, `articles`, `specialites`, `formations_continues`, `certifications`, `promotions`, `enseignants`, `partenaires`, `documents`, `candidatures`.
- **UUIDs publics + bigint internes** (cf. data-model.md §1).
- **Index FK systématiques**.
- **Index trigramme** sur quelques colonnes pour fallback recherche (`documents.title->>'fr'`).

## Tests Pest

- **Feature tests** sur chaque endpoint API public (pattern : un fichier par groupe d'endpoints).
- **Policy tests** sur chaque politique d'accès — notamment `DocumentPolicy`, `CandidaturePolicy` (ownership strict).
- **Filament tests** smoke par Resource : la page liste s'affiche pour un user avec rôle X et n'affiche PAS pour un user avec rôle Y.
- **Seed minimal** pour tests via factories — aucune donnée nominative réelle.

Lancer : `php artisan test` ou `make test-backend`.

## Sécurité

- **Sanctum** pour auth API (cf. ADR-0005). Tokens avec abilities (scopes).
- **Rate limiting** : 60/min public, 300/min auditeur, 600/min teacher. Login : 5/10 min par IP, lockout après 10 échecs sur le même compte.
- **CSRF** sur tous les formulaires Laravel (Filament inclus).
- **CSP stricte** sur `/admin`.
- **Storage privé** pour docs restreints — `StreamedResponse` avec URL signée 30 min.
- **Sanitisation HTML** stockée via `mews/purifier` (whitelist).
- **ClamAV** scan asynchrone sur uploads candidatures et biblio.
- **Headers HTTP** sécurité : HSTS, X-Content-Type-Options, X-Frame-Options DENY, etc.

## Stockage MinIO

- **Disk Laravel** : `minio` (S3-compatible).
- **Buckets** :
  - `pssfp-media` (public read) — images CMS, logos, photos institutionnelles.
  - `pssfp-documents` (private) — PDF biblio.
  - `pssfp-candidatures` (private) — pièces candidatures.
- URLs signées 30 min via `Storage::disk('minio')->temporaryUrl(...)`.

## Recherche Meilisearch

- **Laravel Scout** driver `meilisearch`.
- Indices : `documents`, `articles`, `pages`, `formations`, `enseignants`.
- Synchronisation auto via observers Eloquent + Scout.
- Réindexation full hebdomadaire le dimanche 4h via cron.

## Backups

- **Spatie Laravel Backup** package.
- Cron Laravel : `backup:clean` à 1h, `backup:run` à 3h chaque jour.
- Destinations : MinIO local (rétention 7j) + Scaleway Object Storage (rétention 30j).
- Test de restauration semestriel obligatoire.

## i18n

- Default locale : `fr`. Locales activables : `en`, `ar`.
- Champs traduisibles via `spatie/laravel-translatable`.
- Locale demandée via header `Accept-Language`.

## Variables d'env clés

```
APP_LOCALE=fr
DB_CONNECTION=pgsql
SCOUT_DRIVER=meilisearch
FILESYSTEM_DISK=minio
SESSION_DOMAIN=.pssfp.net
QUEUE_CONNECTION=redis
SANCTUM_STATEFUL_DOMAINS=pssfp.net,bibliotheque.pssfp.net,candidature.pssfp.net
```

Cf. `.env.example` racine pour la liste complète.

## Dépendances clés Composer

- `laravel/framework@^11`
- `laravel/sanctum`
- `laravel/scout`
- `meilisearch/meilisearch-php`
- `filament/filament@^3`
- `spatie/laravel-permission`
- `spatie/laravel-activitylog`
- `spatie/laravel-medialibrary`
- `spatie/laravel-translatable`
- `spatie/laravel-backup`
- `mews/purifier`
- `barryvdh/laravel-dompdf`
- `setasign/fpdi` (filigrane PDF)
- `bezhansalleh/filament-shield`
- `pestphp/pest`
- `sentry/sentry-laravel`

## Sous-agents dédiés

- `.claude/agents/security-reviewer.md` — relit les diffs auth, policies, sanitisation.
- `.claude/agents/filament-reviewer.md` — relit les Resources Filament.
- `.claude/agents/biblio-reviewer.md` — relit la logique biblio (access_level, signed URLs).
- `.claude/agents/candidature-reviewer.md` — relit la logique candidatures (PII, ownership).
