# Contrat API REST — pssfp.net

> **Référence** : Sprint Specs A3
> **Statut** : v1.0 — proposé pour validation Chef USI
> **Date** : 2026-05-05
> **Base URL production** : `https://api.pssfp.net/v1`
> **Base URL développement** : `http://localhost:8000/v1`
> **Format** : JSON, UTF-8
> **Source de vérité** : ce document. Aucun endpoint ne doit exister en production sans être documenté ici.

Ce document décrit le contrat API REST entre les trois applications frontend Next.js (`pssfp.net`, `bibliotheque.pssfp.net`, `candidature.pssfp.net`) et le backend Laravel 11. Il s'aligne sur le modèle de données `data-model.md` et les ADR-0001 (stack), ADR-0005 (auth), ADR-0006 (i18n).

L'API admin Filament n'est pas couverte ici — Filament gère ses propres routes et formulaires sous `api.pssfp.net/admin` avec sessions navigateur, sans REST exposé.

## Sommaire

1. Conventions transverses
2. Authentification et tokens
3. Domaine — Pages institutionnelles
4. Domaine — Actualités et taxonomies
5. Domaine — Formations
6. Domaine — Vie académique
7. Domaine — Partenaires
8. Domaine — Bibliothèque virtuelle
9. Domaine — Candidatures (Module 5)
10. Domaine — Contact
11. Domaine — Paramètres et santé
12. Sécurité, rate limiting et CORS
13. Récapitulatif des endpoints

---

## 1. Conventions transverses

### 1.1 Versioning

Toutes les routes sont préfixées par `/v1`. Une rupture de contrat (suppression d'un champ obligatoire, changement de type, modification sémantique) impose un nouveau préfixe `/v2` avec maintien de `/v1` pendant au moins 6 mois en parallèle. Les ajouts de champs optionnels et nouveaux endpoints ne nécessitent pas de bump majeur.

### 1.2 Format de réponse

Toutes les réponses utilisent l'enveloppe Laravel JsonResource standardisée.

**Pour une ressource unique** :

```json
{
  "data": { /* objet ressource */ }
}
```

**Pour une collection paginée** :

```json
{
  "data": [ /* array de ressources */ ],
  "links": {
    "first": "https://api.pssfp.net/v1/articles?page=1",
    "last":  "https://api.pssfp.net/v1/articles?page=12",
    "prev":  null,
    "next":  "https://api.pssfp.net/v1/articles?page=2"
  },
  "meta": {
    "current_page": 1,
    "last_page": 12,
    "per_page": 20,
    "total": 230,
    "from": 1,
    "to": 20,
    "path": "https://api.pssfp.net/v1/articles"
  }
}
```

**Pour une collection non paginée** (taxonomies, partenaires, courtes listes) :

```json
{
  "data": [ /* array */ ]
}
```

### 1.3 Pagination

Pagination par offset avec query params `?page=N&per_page=M`. Valeurs par défaut : `page=1`, `per_page=20`. Plafonds : `per_page` borné à 100 maximum côté backend (toute valeur > 100 est ramenée à 100, log d'avertissement). Les listes courtes par nature (les 5 spécialités, les ~10 partenaires, les 13 promotions) ne sont **pas paginées** et retournent l'intégralité.

### 1.4 Filtres et tri

Les filtres se passent en query params nommés. Convention :

- Filtre exact : `?category=actualites`
- Filtre array : `?tags[]=cames&tags[]=formation`
- Filtre date : `?from=2026-01-01&to=2026-06-30` (ISO 8601, timezone UTC)
- Recherche full-text : `?q=texte`
- Tri : `?sort=published_at` (descendant par défaut), `?sort=-views_count` (préfixe `-` pour ascendant explicite, `+` toléré)

Les paramètres inconnus sont **ignorés silencieusement** (pas d'erreur 422) — permet aux frontends d'envoyer des filtres expérimentaux sans casser.

### 1.5 Locale et i18n

Le frontend transmet la locale demandée via le header HTTP `Accept-Language: fr | en | ar`. Si absent ou invalide, fallback sur `fr`.

Pour chaque champ traduisible, l'API renvoie **directement la valeur dans la locale demandée** (pas l'objet JSONB complet) :

```json
{ "title": "Présentation", "body": "..." }
```

Si la locale n'a pas de traduction disponible, fallback automatique sur `fr` sans signal d'erreur. Le client peut détecter l'absence via le header de réponse `Content-Language: fr` (la locale réellement servie). Cf. ADR-0006.

### 1.6 Identifiants

Les ressources publiques sont **routées par slug** quand applicable (`/articles/refonte-site-web-pssfp`) ou par UUID (`/library/documents/{uuid}`). L'`id` interne BIGSERIAL n'apparaît **jamais** dans une URL ou une réponse publique — il est strictement interne à la base.

### 1.7 Format des erreurs

Format standard Laravel :

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["Le champ email est obligatoire."],
    "password": ["Le mot de passe doit contenir au moins 12 caractères."]
  }
}
```

Pour les erreurs sans champ spécifique :

```json
{
  "message": "Vous n'avez pas accès à ce document."
}
```

Codes HTTP utilisés systématiquement :

| Code | Usage |
|---|---|
| 200 OK | Succès lecture |
| 201 Created | Succès création |
| 204 No Content | Succès sans corps de réponse (logout, delete) |
| 400 Bad Request | Requête malformée (JSON invalide) |
| 401 Unauthorized | Token absent ou invalide |
| 403 Forbidden | Authentifié mais pas le droit |
| 404 Not Found | Ressource inexistante |
| 409 Conflict | Conflit logique (numéro déjà attribué, etc.) |
| 422 Unprocessable Entity | Validation échouée |
| 429 Too Many Requests | Rate limit dépassé |
| 500 Internal Server Error | Erreur applicative — déclenche Sentry |
| 503 Service Unavailable | Maintenance planifiée |

### 1.8 Headers transverses

**Requête** :

- `Accept: application/json` — obligatoire, sans quoi Laravel renvoie HTML.
- `Authorization: Bearer {token}` — pour les endpoints authentifiés.
- `Accept-Language: fr|en|ar` — locale demandée. Défaut `fr`.
- `Content-Type: application/json` ou `multipart/form-data` (uploads).
- `X-Idempotency-Key: {uuid}` — recommandé sur les POST sensibles (création candidature, soumission, paiement Phase II) pour éviter les doublons en cas de retry réseau.

**Réponse** :

- `Content-Type: application/json; charset=utf-8`.
- `Content-Language: fr|en|ar` — locale réellement servie (peut différer de la demandée si fallback).
- `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`.
- `X-Request-ID: {uuid}` — identifiant unique de la requête, traçable dans les logs Laravel et Sentry.

### 1.9 Cache HTTP

Les ressources publiques exposent des en-têtes `Cache-Control` et `ETag` pour permettre la mise en cache CDN (Cloudflare en Phase II) et le `If-None-Match` côté client.

| Type | Cache-Control |
|---|---|
| Page institutionnelle, formation | `public, max-age=300, s-maxage=3600` |
| Article actualité | `public, max-age=60, s-maxage=300` |
| Liste articles | `public, max-age=60` |
| Recherche bibliothèque | `private, no-cache` |
| Mon profil, mes favoris, candidature | `private, no-store` |
| Paramètres globaux | `public, max-age=600` |

### 1.10 Conventions de nommage des champs

Les noms de champs sortent en `snake_case` (cohérent avec PostgreSQL et Laravel). Les frontends Next.js peuvent transformer en `camelCase` côté client si la convention TypeScript locale l'exige, via une couche de mapping.

Les dates sortent en **ISO 8601 avec offset** : `"2026-05-05T14:30:00+01:00"`. Les durées en secondes (entiers) sauf cas évident.

---

## 2. Authentification et tokens

Cf. ADR-0005. Les tokens Sanctum sont obtenus via les endpoints d'authentification ci-dessous. Le frontend Next.js stocke ensuite le token dans un cookie httpOnly Secure SameSite=Lax émis par le backend (jamais en localStorage). Les abilities (scopes) sont attribuées par rôle utilisateur lors de la délivrance.

### `POST /v1/auth/register`

Inscription nouveau compte. Utilisé principalement pour les candidats (création directe de compte avec email vérifié à confirmer).

**Auth** : public.
**Rate limit** : 3 tentatives par 10 minutes par IP.
**Body** :

```json
{
  "email": "candidat@example.com",
  "password": "MotDePasseRobuste123!",
  "password_confirmation": "MotDePasseRobuste123!",
  "first_name": "Jean",
  "last_name": "Dupont",
  "phone": "+237691234567"
}
```

**Response 201** :

```json
{
  "data": {
    "user": { "uuid": "...", "email": "candidat@example.com", "first_name": "Jean", "last_name": "Dupont" },
    "email_verification_required": true
  }
}
```

**Errors** : 422 (validation, email déjà utilisé, password trop faible), 429 (rate limit).

### `POST /v1/auth/login`

Authentification email + mot de passe. Si 2FA activé sur le compte, retourne `requires_2fa: true` et un token temporaire à utiliser pour `POST /v1/auth/2fa/verify`.

**Auth** : public.
**Rate limit** : 5 tentatives par 10 minutes par IP. Lockout 15 min après 10 échecs sur un même compte.
**Body** :

```json
{
  "email": "candidat@example.com",
  "password": "MotDePasseRobuste123!",
  "remember": false
}
```

**Response 200 (sans 2FA)** :

```json
{
  "data": {
    "user": { /* User resource */ },
    "token": "1|xKfg5JPq...",
    "abilities": ["application:read", "application:create", "profile:read", "profile:write"],
    "expires_at": "2026-05-06T14:30:00+01:00"
  }
}
```

**Response 200 (avec 2FA)** :

```json
{
  "data": {
    "requires_2fa": true,
    "challenge_token": "challenge_xKfg5JPq...",
    "expires_at": "2026-05-05T14:35:00+01:00"
  }
}
```

**Errors** : 401 (credentials invalides), 422, 423 (compte verrouillé), 429.

### `POST /v1/auth/2fa/verify`

Validation du code TOTP en complément du login.

**Auth** : challenge_token reçu de `/login`.
**Body** : `{ "challenge_token": "...", "code": "123456" }`
**Response 200** : Identique à login sans 2FA (token + abilities).
**Errors** : 401 (code invalide), 410 (challenge expiré), 429.

### `POST /v1/auth/logout`

Révoque le token courant.

**Auth** : Sanctum.
**Response 204** : pas de corps.

### `GET /v1/auth/me`

Retourne le profil de l'utilisateur authentifié.

**Auth** : Sanctum.
**Response 200** :

```json
{
  "data": {
    "uuid": "...",
    "email": "candidat@example.com",
    "first_name": "Jean",
    "last_name": "Dupont",
    "phone": "+237691234567",
    "avatar_url": null,
    "roles": [],
    "abilities": ["application:read", "application:create"],
    "two_factor_enabled": false,
    "email_verified_at": "2026-05-05T14:30:00+01:00",
    "candidate_profile": { /* ou null si pas de profil candidat */ }
  }
}
```

### `POST /v1/auth/email/verify/{id}/{hash}`

Lien envoyé par email à l'inscription. Active le compte.

**Auth** : public.
**Response 200** : `{ "data": { "verified": true } }`
**Errors** : 410 (lien expiré), 422 (lien invalide).

### `POST /v1/auth/email/resend`

Renvoyer le mail de vérification.

**Auth** : Sanctum (l'utilisateur doit être loggé même si non vérifié).
**Rate limit** : 1 par 60 secondes.
**Response 204**.

### `POST /v1/auth/password/forgot`

Demande de réinitialisation. Envoie un email avec lien signé.

**Auth** : public.
**Rate limit** : 3 par 10 min par email.
**Body** : `{ "email": "..." }`
**Response 204** : silencieux même si email inconnu (anti énumération).

### `POST /v1/auth/password/reset`

Soumet nouveau mot de passe avec token reçu par email.

**Auth** : public.
**Body** :

```json
{
  "token": "...",
  "email": "...",
  "password": "...",
  "password_confirmation": "..."
}
```

**Response 200** : `{ "data": { "reset": true } }`
**Errors** : 422.

### `POST /v1/auth/2fa/enable`

Active le 2FA sur le compte. Retourne le secret TOTP en base32 + QR code data URL pour scanner dans une app authenticator.

**Auth** : Sanctum.
**Response 200** :

```json
{
  "data": {
    "secret": "JBSWY3DPEHPK3PXP",
    "qr_code_svg": "data:image/svg+xml;base64,..."
  }
}
```

### `POST /v1/auth/2fa/confirm`

Confirme l'activation 2FA avec un premier code valide. Retourne les codes de récupération.

**Auth** : Sanctum.
**Body** : `{ "code": "123456" }`
**Response 200** : `{ "data": { "recovery_codes": ["...", "..."] } }`

### `POST /v1/auth/2fa/disable`

Désactive le 2FA. Demande mot de passe en confirmation.

**Auth** : Sanctum.
**Body** : `{ "password": "..." }`
**Response 204**.

---

## 3. Domaine — Pages institutionnelles

Pages éditables par l'admin via Filament, exposées en lecture publique.

### `GET /v1/pages`

Liste de toutes les pages publiées (utile pour sitemap, prefetch Next.js).

**Auth** : public.
**Query** : `?in_menu=true` filtre les pages présentes dans la nav, `?parent_slug=pssfp` filtre les enfants d'une page.
**Response 200** :

```json
{
  "data": [
    {
      "uuid": "...",
      "slug": "presentation",
      "title": "Présentation du PSSFP",
      "excerpt": "...",
      "parent_slug": "pssfp",
      "menu_label": "Présentation",
      "order": 1,
      "is_in_menu": true,
      "published_at": "2026-04-01T10:00:00+01:00",
      "updated_at": "2026-04-15T08:30:00+01:00"
    }
  ]
}
```

### `GET /v1/pages/{slug}`

Détail d'une page par slug. Le `slug` peut être hiérarchique : `/v1/pages/pssfp/presentation`.

**Auth** : public.
**Response 200** :

```json
{
  "data": {
    "uuid": "...",
    "slug": "presentation",
    "parent_slug": "pssfp",
    "title": "Présentation du PSSFP",
    "excerpt": "...",
    "body": "<h2>...</h2><p>...</p>",
    "body_format": "html",
    "meta_title": "...",
    "meta_description": "...",
    "og_image_url": "https://media.pssfp.net/.../og.webp",
    "breadcrumb": [
      { "slug": null, "title": "Accueil" },
      { "slug": "pssfp", "title": "Le PSSFP" },
      { "slug": "presentation", "title": "Présentation" }
    ],
    "published_at": "...",
    "updated_at": "..."
  }
}
```

**Errors** : 404 si slug inconnu ou page non publiée.

### `GET /v1/menu`

Structure complète de la navigation principale. Dérivée des pages `is_in_menu = true` + entrées dynamiques (rubriques fixes). Mise en cache 10 min côté serveur.

**Auth** : public.
**Response 200** :

```json
{
  "data": [
    { "label": "Accueil", "path": "/", "type": "home" },
    { "label": "Le PSSFP", "path": "/pssfp", "type": "section", "children": [
      { "label": "Présentation", "path": "/pssfp/presentation" },
      { "label": "Mot du Président", "path": "/pssfp/mot-president" },
      { "label": "Gouvernance", "path": "/pssfp/gouvernance" },
      { "label": "Organigramme", "path": "/pssfp/organigramme" },
      { "label": "Conventions", "path": "/pssfp/conventions" },
      { "label": "Infrastructure", "path": "/pssfp/infrastructure" },
      { "label": "Partenaires", "path": "/pssfp/partenaires" },
      { "label": "Conformité CAMES", "path": "/pssfp/conformite-cames" }
    ]},
    { "label": "Formations", "path": "/formations", "type": "section", "children": [...] },
    { "label": "Candidature", "path": "/candidature", "type": "external", "external_url": "https://candidature.pssfp.net" },
    { "label": "Vie Académique", "path": "/vie-academique", "type": "section", "children": [...] },
    { "label": "Actualités", "path": "/actualites", "type": "section" },
    { "label": "Bibliothèque", "path": "https://bibliotheque.pssfp.net", "type": "external" },
    { "label": "Contact", "path": "/contact", "type": "page" }
  ]
}
```

---

## 4. Domaine — Actualités et taxonomies

### `GET /v1/articles`

Liste paginée des articles publiés.

**Auth** : public.
**Query** : `?category={slug}&tag={slug}&author={user_uuid}&q={search}&from={date}&to={date}&page=1&per_page=20&sort=-published_at`.
**Response 200** : enveloppe paginée. Items :

```json
{
  "uuid": "...",
  "slug": "ouverture-promotion-14",
  "title": "Ouverture des candidatures Promotion 14",
  "excerpt": "Le PSSFP ouvre les candidatures...",
  "featured_image": {
    "url": "https://media.pssfp.net/.../article-hero.webp",
    "alt": "Salle de classe Campus Messa",
    "width": 1920, "height": 1080
  },
  "category": { "slug": "candidature", "name": "Candidature", "color": "6B2FA0" },
  "tags": [{ "slug": "promotion-14", "name": "Promotion 14" }],
  "author": { "uuid": "...", "first_name": "Anatole", "last_name": "Abe Etoumou" },
  "is_pinned": true,
  "published_at": "2026-05-01T08:00:00+01:00",
  "reading_time_minutes": 3
}
```

### `GET /v1/articles/featured`

Articles épinglés pour la page d'accueil. Limité à 5 résultats.

**Auth** : public.
**Response 200** : `{ "data": [...] }`.

### `GET /v1/articles/{slug}`

Détail d'un article. Incrémente `views_count` (asynchrone via job pour ne pas bloquer la réponse).

**Auth** : public.
**Response 200** :

```json
{
  "data": {
    "uuid": "...", "slug": "...", "title": "...", "excerpt": "...",
    "body": "<p>...</p>", "body_format": "html",
    "featured_image": { ... },
    "category": { ... },
    "tags": [ ... ],
    "author": { ... },
    "meta_title": "...", "meta_description": "...",
    "published_at": "...", "updated_at": "...",
    "reading_time_minutes": 3,
    "views_count": 142,
    "related_articles": [ /* 3 articles de la même catégorie */ ]
  }
}
```

### `GET /v1/categories`

Liste complète des catégories (non paginée).

**Auth** : public.
**Response 200** : `{ "data": [{ "slug": "...", "name": "...", "description": "...", "color": "...", "icon": "...", "articles_count": 12 }] }`.

### `GET /v1/categories/{slug}`

Détail d'une catégorie.

**Response 200** : objet catégorie + 5 derniers articles.

### `GET /v1/tags`

Liste des tags utilisés (non paginée, triés par fréquence d'usage descendante).

**Response 200** : `{ "data": [{ "slug": "...", "name": "...", "articles_count": 8 }] }`.

---

## 5. Domaine — Formations

### `GET /v1/formations/specialites`

Liste des 5 spécialités du Master.

**Auth** : public.
**Response 200** :

```json
{
  "data": [
    {
      "uuid": "...",
      "code": "fiscalite",
      "slug": "fiscalite-finance-comptabilite-publique",
      "nom": "Fiscalité, Finance et Comptabilité Publique",
      "nom_court": "Fiscalité",
      "description_courte": "...",
      "color": "6B2FA0",
      "icon": "calculator",
      "illustration": { "url": "...", "alt": "..." },
      "order": 2,
      "ue_count": 12,
      "enseignants_count": 8
    }
  ]
}
```

### `GET /v1/formations/specialites/{slug}`

Fiche complète d'une spécialité.

**Response 200** :

```json
{
  "data": {
    "uuid": "...", "code": "fiscalite", "slug": "...", "nom": "...", "nom_court": "...",
    "description_courte": "...", "description_longue": "<p>Markdown HTML rendered</p>",
    "debouches": ["Inspecteur des impôts", "Auditeur fiscal", "..."],
    "color": "...", "illustration": { ... },
    "unites_enseignement": [
      { "code": "UE-FIS-301", "intitule": "Droit fiscal approfondi", "semestre": 3, "volume_horaire": 30, "credits_ects": 4 }
    ],
    "enseignants": [
      { "uuid": "...", "civilite": "Pr.", "prenom": "...", "nom": "...", "slug": "...", "grade": "Pr titulaire", "photo_url": "..." }
    ],
    "meta_title": "...", "meta_description": "..."
  }
}
```

### `GET /v1/formations/tronc-commun`

UE du tronc commun (S1-S2). Toutes UE avec `specialite_id IS NULL`.

**Response 200** :

```json
{
  "data": {
    "description": "Le tronc commun de 1ère année couvre les fondamentaux...",
    "unites_enseignement": [
      { "code": "UE-TC-101", "intitule": "Cadrage macroéconomique", "semestre": 1, "volume_horaire": 30, "credits_ects": 4 }
    ]
  }
}
```

### `GET /v1/formations/continues`

Liste des 10 modules de formation continue.

**Auth** : public.
**Response 200** : `{ "data": [...] }` — items avec `slug`, `numero`, `intitule`, `description`, `cibles`, `duree_jours`, tarifs.

### `GET /v1/formations/continues/{slug}`

Fiche module formation continue.

### `GET /v1/formations/certifications`

Liste certifications + voyages d'étude.

### `GET /v1/formations/certifications/{slug}`

Fiche certification ou voyage.

---

## 6. Domaine — Vie académique

### `GET /v1/promotions`

Liste des 13 promotions, triée par `numero` descendant par défaut.

**Response 200** :

```json
{
  "data": [
    {
      "uuid": "...",
      "numero": 13,
      "annee_debut": 2025,
      "annee_fin": null,
      "status": "en_cours",
      "nombre_etudiants": 200,
      "photo_groupe": { "url": "...", "alt": "Promotion 13" }
    }
  ]
}
```

### `GET /v1/promotions/{numero}`

Détail d'une promotion.

### `GET /v1/enseignants`

Liste paginée du corps enseignant.

**Query** : `?specialite={slug}&q={search}&page=1&per_page=20`.
**Response 200** : items `{ uuid, civilite, prenom, nom, slug, grade, qualifications, domaines, photo_url, specialites: [...] }`.

### `GET /v1/enseignants/{slug}`

Fiche détaillée enseignant : bio, publications, UE enseignées.

### `GET /v1/evenements`

Calendrier filtrable.

**Query** : `?from=2026-05-01&to=2026-12-31&type=seminaire&is_public=true&page=1`.
**Response 200** : items `{ uuid, slug, titre, description, type, starts_at, ends_at, all_day, location, lat, lng, illustration }`.

### `GET /v1/evenements/{slug}`

Détail événement.

---

## 7. Domaine — Partenaires

### `GET /v1/partenaires`

Liste complète des partenaires (non paginée).

**Query** : `?type=institutionnel|technique|academique|client&featured=true`.
**Response 200** :

```json
{
  "data": [
    {
      "uuid": "...",
      "slug": "minfi",
      "nom": "Ministère des Finances du Cameroun",
      "type": "institutionnel",
      "description": "Tutelle institutionnelle principale...",
      "logo": { "url": "https://media.pssfp.net/.../minfi.svg", "format": "svg" },
      "site_url": "https://www.minfi.gov.cm",
      "convention_pdf_url": "https://media.pssfp.net/.../convention-minfi-2024.pdf",
      "is_featured": true,
      "order": 1
    }
  ]
}
```

---

## 8. Domaine — Bibliothèque virtuelle

API consommée par `bibliotheque.pssfp.net`. Cf. CDC bibliothèque v2.0 et data-model.md §8.

### `GET /v1/library/documents`

Liste paginée des documents.

**Auth** : public pour `access_level=public`. Sanctum + ability `library:read:restricted` requis pour voir les documents `auditor`/`teacher` dans la liste (sinon ils sont filtrés silencieusement).
**Query** : `?type=these|memoire|article_scientifique|texte_loi|cours|...&specialite={slug}&promotion={numero}&year={year}&language=fr|en&access_level=public&q={search}&sort=-published_at&page=1&per_page=20`.
**Response 200** : items :

```json
{
  "uuid": "...",
  "slug": "these-fiscalite-2024-mballa",
  "type": "these",
  "title": "La fiscalité pétrolière en zone CEMAC",
  "subtitle": null,
  "abstract": "...",
  "year": 2024,
  "language": "fr",
  "promotion": { "numero": 11, "annee_debut": 2023 },
  "specialite": { "slug": "fiscalite", "nom_court": "Fiscalité" },
  "authors": [
    { "name": "MBALLA Jean", "type": "user", "user_uuid": "..." }
  ],
  "keywords": ["fiscalité pétrolière", "CEMAC", "douanes"],
  "pages_count": 145,
  "thumbnail": { "url": "...", "alt": "Couverture thèse" },
  "access_level": "public",
  "downloads_count": 28,
  "is_favorited": false,
  "published_at": "2024-09-15T00:00:00+01:00"
}
```

`is_favorited` est `null` pour les utilisateurs non authentifiés, `true`/`false` pour les authentifiés.

### `GET /v1/library/documents/{uuid}`

Détail métadonnées d'un document.

**Auth** : selon `access_level`. Si `access_level != public` et utilisateur non autorisé → 403.
**Response 200** : items + champs supplémentaires `journal`, `doi`, `isbn`, `file_size_bytes`, citation pre-générée, related_documents.

### `GET /v1/library/documents/{uuid}/download`

Téléchargement du PDF. Pour les documents publics, redirige (302) vers une URL signée MinIO/Scaleway temporaire (validité 30 min). Pour les documents restreints, vérifie l'auth + ability avant de signer. Trace dans `document_downloads`.

**Auth** : selon `access_level`.
**Response 302** : redirection vers URL signée.
**Errors** : 403, 404.

### `GET /v1/library/search`

Recherche full-text via Meilisearch.

**Auth** : public (résultats filtrés selon auth).
**Query** : `?q={query}&filters[type]=these&filters[specialite]=fiscalite&filters[promotion]=11&filters[year_from]=2020&filters[year_to]=2024&sort=-relevance&page=1&per_page=20`.
**Response 200** :

```json
{
  "data": [
    {
      "uuid": "...", "slug": "...", "type": "these", "title": "...",
      "abstract": "...", "year": 2024,
      "highlights": {
        "title": "<mark>fiscalité</mark> pétrolière en zone CEMAC",
        "abstract": "...la <mark>fiscalité</mark> indirecte..."
      },
      "score": 0.94
    }
  ],
  "meta": {
    "total": 42,
    "search_time_ms": 12,
    "facets": {
      "type": { "these": 18, "memoire": 12, "article_scientifique": 8, "cours": 4 },
      "specialite": { "fiscalite": 28, "audit": 8, "marches-publics": 6 },
      "year": { "2024": 5, "2023": 12, "2022": 10, "2021": 8, "2020": 7 }
    }
  }
}
```

### `GET /v1/library/keywords`

Auto-complétion mots-clés. Retour limité à 10 suggestions.

**Query** : `?prefix=fis`.
**Response 200** : `{ "data": [{ "slug": "fiscalite", "label": "Fiscalité" }, ...] }`.

### `GET /v1/library/citations/{uuid}`

Génère une citation bibliographique formatée.

**Query** : `?format=apa|mla|chicago|bibtex`.
**Response 200** :

```json
{
  "data": {
    "format": "apa",
    "citation": "MBALLA, J. (2024). La fiscalité pétrolière en zone CEMAC (Mémoire de Master). Programme Supérieur de Spécialisation en Finances Publiques."
  }
}
```

### `GET /v1/library/favorites`

Mes favoris. Pagination identique à `/library/documents`.

**Auth** : Sanctum + ability `library:favorites`.
**Response 200** : enveloppe paginée d'objets document.

### `POST /v1/library/favorites`

Ajouter un favori.

**Auth** : Sanctum + `library:favorites`.
**Body** : `{ "document_uuid": "..." }`.
**Response 201** : `{ "data": { "favorited": true, "document_uuid": "..." } }`.

### `DELETE /v1/library/favorites/{document_uuid}`

Retirer un favori.

**Auth** : Sanctum + `library:favorites`.
**Response 204**.

---

## 9. Domaine — Candidatures (Module 5)

API consommée par `candidature.pssfp.net`. Le candidat crée son compte via `/v1/auth/register`, complète son `candidate_profile`, soumet une candidature liée à une `campagne` ouverte.

### `GET /v1/applications/campaigns`

Campagnes de candidature visibles publiquement. Statuts `open`, `results_published` retournés ; `draft` et `archived` filtrés.

**Auth** : public.
**Response 200** :

```json
{
  "data": [
    {
      "uuid": "...",
      "slug": "campagne-2026",
      "nom": "Campagne d'admission 2026 — Promotion 14",
      "description": "...",
      "promotion": { "numero": 14, "annee_debut": 2026 },
      "opens_at": "2026-04-01T00:00:00+01:00",
      "closes_at": "2026-07-31T23:59:59+01:00",
      "results_at": "2026-08-15T10:00:00+01:00",
      "status": "open",
      "is_currently_open": true,
      "frais_candidature": 25000,
      "max_voeux": 3,
      "pieces_requises": [
        { "type": "cv", "label": "CV détaillé", "required": true },
        { "type": "diplome", "label": "Copie certifiée du diplôme", "required": true },
        { "type": "lettre_motivation", "label": "Lettre de motivation", "required": true },
        { "type": "photo", "label": "Photo identité récente", "required": true },
        { "type": "piece_identite", "label": "Pièce d'identité", "required": true },
        { "type": "releve_notes", "label": "Relevés de notes Licence", "required": true }
      ]
    }
  ]
}
```

### `GET /v1/applications/campaigns/{slug}`

Détail d'une campagne. Inclut champs descriptifs supplémentaires (modalités, contact comité).

### `GET /v1/applications/profile`

Mon profil candidat.

**Auth** : Sanctum + `profile:read`.
**Response 200** :

```json
{
  "data": {
    "user": { "uuid": "...", "email": "...", "first_name": "...", "last_name": "...", "phone": "..." },
    "date_naissance": "1990-04-12",
    "lieu_naissance": "Yaoundé",
    "nationalite": "CM",
    "genre": "M",
    "situation_familiale": "celibataire",
    "pays_residence": "CM",
    "ville_residence": "Yaoundé",
    "adresse": "...",
    "profession_actuelle": "Inspecteur des impôts",
    "employeur": "DGI",
    "anciennete_annees": 5,
    "diplome_max": "Master",
    "etablissement_diplome": "Université de Yaoundé II",
    "annee_diplome": 2014,
    "is_complete": true
  }
}
```

`is_complete` est calculé serveur — passe à `true` quand tous les champs requis sont renseignés.

### `PUT /v1/applications/profile`

Créer ou mettre à jour le profil candidat. Upsert.

**Auth** : Sanctum + `profile:write`.
**Body** : tous les champs ci-dessus, sauf `user.*` (immutable, modifiable via `/auth/me`).
**Response 200** : profil mis à jour.

### `GET /v1/applications`

Mes candidatures (toutes campagnes confondues).

**Auth** : Sanctum + `application:read`.
**Response 200** : items :

```json
{
  "uuid": "...",
  "numero": "C-2026-0042",
  "campagne": { "slug": "campagne-2026", "nom": "..." },
  "voeux": [
    { "ordre": 1, "specialite": { "slug": "fiscalite", "nom": "..." } },
    { "ordre": 2, "specialite": { "slug": "audit", "nom": "..." } },
    { "ordre": 3, "specialite": { "slug": "marches-publics", "nom": "..." } }
  ],
  "status": "submitted",
  "status_label": "Dossier soumis — en attente d'examen",
  "submitted_at": "2026-05-15T14:30:00+01:00",
  "frais_paye": false,
  "documents_count": 6,
  "documents_required_count": 6,
  "is_complete": true,
  "can_be_modified": false,
  "can_be_withdrawn": true
}
```

### `POST /v1/applications`

Créer une nouvelle candidature en brouillon.

**Auth** : Sanctum + `application:create`.
**Body** :

```json
{
  "campagne_slug": "campagne-2026",
  "voeu_1_specialite_slug": "fiscalite",
  "voeu_2_specialite_slug": "audit",
  "voeu_3_specialite_slug": "marches-publics"
}
```

**Response 201** : objet candidature avec `numero` généré, `status: "draft"`.
**Errors** : 422 (campagne fermée, profil candidat incomplet, candidat déjà inscrit dans cette campagne), 409.

### `GET /v1/applications/{numero}`

Détail d'une candidature. Le candidat ne voit que les siennes.

**Auth** : Sanctum + `application:read`. Vérifie ownership.
**Response 200** : objet candidature complet avec `documents`, `comments` (uniquement les commentaires `is_internal: false` visibles candidat), `status_history`.

### `PUT /v1/applications/{numero}`

Modifier une candidature en brouillon (vœux, projet pro, motivation).

**Auth** : Sanctum + `application:create`.
**Body** : champs modifiables.
**Response 200** : candidature mise à jour.
**Errors** : 422, 409 (déjà soumise, non modifiable).

### `POST /v1/applications/{numero}/submit`

Soumet définitivement la candidature. Vérifie que le profil candidat est complet, que toutes les pièces requises sont uploadées, et bascule le statut `draft` → `submitted`. Génère le récépissé PDF.

**Auth** : Sanctum + `application:create`.
**Response 200** : candidature avec `status: "submitted"`, `submitted_at` rempli, `recipisse_url` disponible.
**Errors** : 422 (profil ou pièces incomplètes — détail `errors.profile`, `errors.documents`), 409 (déjà soumise).

### `POST /v1/applications/{numero}/withdraw`

Retirer une candidature. Possible tant que le statut est `submitted` ou `under_review`.

**Auth** : Sanctum + `application:create`.
**Body** : `{ "reason": "..." }` optionnel.
**Response 200** : candidature avec `status: "withdrawn"`.

### `POST /v1/applications/{numero}/documents`

Upload d'une pièce jointe.

**Auth** : Sanctum + `application:create`.
**Content-Type** : `multipart/form-data`.
**Body** : `type` (string, enum), `label` (string, optionnel), `file` (binary). Limite 10 MB par fichier, types autorisés : PDF, JPG, PNG.
**Response 201** :

```json
{
  "data": {
    "id": 42,
    "candidature_numero": "C-2026-0042",
    "type": "cv",
    "label": "CV Jean Dupont",
    "file_name": "cv-jean-dupont.pdf",
    "file_size_bytes": 524288,
    "mime_type": "application/pdf",
    "uploaded_at": "2026-05-15T14:25:00+01:00",
    "download_url": "https://api.pssfp.net/v1/applications/C-2026-0042/documents/42/download"
  }
}
```

### `DELETE /v1/applications/{numero}/documents/{id}`

Supprimer une pièce. Possible uniquement en statut `draft` ou `complement_requested`.

**Auth** : Sanctum + `application:create`.
**Response 204**.

### `GET /v1/applications/{numero}/documents/{id}/download`

Télécharger une pièce. URL signée MinIO/Scaleway de 30 min.

**Auth** : Sanctum + `application:read` (candidat propriétaire) ou rôle `admission_committee` (membre comité).
**Response 302** : redirection vers URL signée.

### `GET /v1/applications/{numero}/recipisse`

Télécharger le PDF du récépissé de dépôt généré à la soumission.

**Auth** : Sanctum + `application:read`.
**Response 302** : redirection vers URL signée du PDF.

---

## 10. Domaine — Contact

### `POST /v1/contact`

Envoie un message via le formulaire de contact public. Stocke en BDD et envoie l'email via SMTP `mail.creawebhosting.org` vers `contact@pssfp.net`.

**Auth** : public.
**Rate limit** : 5 par heure par IP, 1 par minute.
**Body** :

```json
{
  "name": "Marie Dupont",
  "email": "marie@example.com",
  "phone": "+237691234567",
  "organisation": "Mairie de Yaoundé 4",
  "subject": "Demande d'information sur formation continue",
  "message": "Bonjour, je souhaiterais...",
  "captcha_token": "..."
}
```

`captcha_token` : token Cloudflare Turnstile (alternative à reCAPTCHA, gratuit, RGPD friendly). À implémenter en frontend.

**Response 201** : `{ "data": { "received": true, "message": "Votre message a bien été reçu." } }`.
**Errors** : 422 (validation, captcha invalide), 429.

---

## 11. Domaine — Paramètres et santé

### `GET /v1/settings`

Paramètres applicatifs publics (titre site, contact, réseaux sociaux, etc.). Mis en cache 10 min.

**Auth** : public.
**Response 200** :

```json
{
  "data": {
    "site_title": "PSSFP — Programme Supérieur de Spécialisation en Finances Publiques",
    "site_description": "Institution de formation d'excellence en finances publiques au Cameroun.",
    "contact_email": "contact@pssfp.net",
    "contact_phone": "+237 222 23 11 71",
    "campus_address": "Campus de Messa, Yaoundé, Cameroun",
    "campus_lat": 3.847,
    "campus_lng": 11.502,
    "social_facebook": "https://facebook.com/pssfpcameroun",
    "social_linkedin": null,
    "social_twitter": null,
    "moodle_url": "https://foad.pssfp.net",
    "candidature_url": "https://candidature.pssfp.net",
    "library_url": "https://bibliotheque.pssfp.net",
    "current_application_campaign_slug": "campagne-2026",
    "available_locales": ["fr"]
  }
}
```

### `GET /v1/health`

Healthcheck pour Uptime Kuma et load balancer.

**Auth** : public.
**Response 200** : `{ "status": "ok", "version": "1.0.0", "timestamp": "...", "checks": { "database": "ok", "redis": "ok", "meilisearch": "ok", "storage": "ok" } }`.
**Response 503** si une dépendance critique est down (database, redis).

### `GET /v1/sitemap.xml`

Sitemap XML pour les moteurs de recherche. Format `urlset` standard, tous les contenus publiés.

**Auth** : public.
**Response 200** : XML.

---

## 12. Sécurité, rate limiting et CORS

### 12.1 Rate limiting

Limites globales par IP, appliquées via Laravel `RateLimiter`. Spécifiques aux endpoints sensibles indiqués au cas par cas.

| Tier | Limite |
|---|---|
| Public anonyme | 60 req/min par IP |
| Authentifié auditeur | 300 req/min par utilisateur |
| Authentifié enseignant | 600 req/min par utilisateur |
| Login / Register / Password forgot | 5/10 min par IP |
| Contact form | 5/h par IP |
| Upload documents | 30/h par utilisateur |

Headers de réponse `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset` toujours présents.

### 12.2 CORS

Origins autorisés : `https://pssfp.net`, `https://www.pssfp.net`, `https://bibliotheque.pssfp.net`, `https://candidature.pssfp.net`. En développement : `http://localhost:3000`, `http://localhost:3001`, `http://localhost:3002` (les trois apps Next.js sur ports différents).

Headers exposés au frontend : `Content-Language`, `X-RateLimit-*`, `X-Request-ID`.

Méthodes autorisées : GET, POST, PUT, PATCH, DELETE, OPTIONS.

Credentials : `withCredentials = true` côté frontend pour transporter le cookie d'auth.

### 12.3 Headers de sécurité

Tous les endpoints renvoient :

- `Strict-Transport-Security: max-age=31536000; includeSubDomains; preload`
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY` (sauf endpoints volontairement embeddables — aucun en V1)
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Content-Security-Policy: default-src 'self'; ...` (à durcir page par page)

### 12.4 Validation et anti-injection

- Tous les FormRequest Laravel valident strictement les types et longueurs.
- Sanitisation des entrées HTML stockées (body articles, body pages) via `mews/purifier` ou équivalent — whitelist d'éléments et attributs.
- Échappement systématique en sortie côté Next.js (React échappe par défaut).
- Uploads : analyse antivirus (ClamAV via job différé) avant publication des fichiers téléchargeables.

### 12.5 Audit

Toute requête mutante (POST, PUT, PATCH, DELETE) sur les ressources sensibles (documents, candidatures, users, settings) est tracée dans `activity_log` avec auteur, IP, payload (sans secrets), timestamp.

---

## 13. Récapitulatif des endpoints

| # | Méthode | URL | Auth | Description |
|---|---|---|---|---|
| 1 | POST | `/v1/auth/register` | public | Inscription |
| 2 | POST | `/v1/auth/login` | public | Connexion |
| 3 | POST | `/v1/auth/2fa/verify` | challenge | Validation 2FA |
| 4 | POST | `/v1/auth/logout` | sanctum | Déconnexion |
| 5 | GET | `/v1/auth/me` | sanctum | Mon profil |
| 6 | POST | `/v1/auth/email/verify/{id}/{hash}` | public | Vérification email |
| 7 | POST | `/v1/auth/email/resend` | sanctum | Renvoyer mail vérif |
| 8 | POST | `/v1/auth/password/forgot` | public | Mot de passe oublié |
| 9 | POST | `/v1/auth/password/reset` | public | Reset mot de passe |
| 10 | POST | `/v1/auth/2fa/enable` | sanctum | Activer 2FA |
| 11 | POST | `/v1/auth/2fa/confirm` | sanctum | Confirmer 2FA |
| 12 | POST | `/v1/auth/2fa/disable` | sanctum | Désactiver 2FA |
| 13 | GET | `/v1/pages` | public | Liste pages |
| 14 | GET | `/v1/pages/{slug}` | public | Page par slug |
| 15 | GET | `/v1/menu` | public | Navigation |
| 16 | GET | `/v1/articles` | public | Liste articles |
| 17 | GET | `/v1/articles/featured` | public | Articles épinglés |
| 18 | GET | `/v1/articles/{slug}` | public | Article détail |
| 19 | GET | `/v1/categories` | public | Catégories |
| 20 | GET | `/v1/categories/{slug}` | public | Catégorie + articles |
| 21 | GET | `/v1/tags` | public | Tags |
| 22 | GET | `/v1/formations/specialites` | public | 5 spécialités |
| 23 | GET | `/v1/formations/specialites/{slug}` | public | Fiche spécialité |
| 24 | GET | `/v1/formations/tronc-commun` | public | UE tronc commun |
| 25 | GET | `/v1/formations/continues` | public | 10 modules FC |
| 26 | GET | `/v1/formations/continues/{slug}` | public | Fiche module FC |
| 27 | GET | `/v1/formations/certifications` | public | Certifications |
| 28 | GET | `/v1/formations/certifications/{slug}` | public | Fiche certification |
| 29 | GET | `/v1/promotions` | public | 13 promotions |
| 30 | GET | `/v1/promotions/{numero}` | public | Détail promotion |
| 31 | GET | `/v1/enseignants` | public | Corps enseignant |
| 32 | GET | `/v1/enseignants/{slug}` | public | Fiche enseignant |
| 33 | GET | `/v1/evenements` | public | Calendrier |
| 34 | GET | `/v1/evenements/{slug}` | public | Événement |
| 35 | GET | `/v1/partenaires` | public | Partenaires |
| 36 | GET | `/v1/library/documents` | public/sanctum | Liste docs biblio |
| 37 | GET | `/v1/library/documents/{uuid}` | public/sanctum | Détail doc |
| 38 | GET | `/v1/library/documents/{uuid}/download` | public/sanctum | Téléchargement PDF |
| 39 | GET | `/v1/library/search` | public | Recherche Meilisearch |
| 40 | GET | `/v1/library/keywords` | public | Auto-complétion mots-clés |
| 41 | GET | `/v1/library/citations/{uuid}` | public | Citation bibliographique |
| 42 | GET | `/v1/library/favorites` | sanctum | Mes favoris |
| 43 | POST | `/v1/library/favorites` | sanctum | Ajouter favori |
| 44 | DELETE | `/v1/library/favorites/{uuid}` | sanctum | Retirer favori |
| 45 | GET | `/v1/applications/campaigns` | public | Campagnes |
| 46 | GET | `/v1/applications/campaigns/{slug}` | public | Campagne détail |
| 47 | GET | `/v1/applications/profile` | sanctum | Mon profil candidat |
| 48 | PUT | `/v1/applications/profile` | sanctum | Maj profil candidat |
| 49 | GET | `/v1/applications` | sanctum | Mes candidatures |
| 50 | POST | `/v1/applications` | sanctum | Créer candidature |
| 51 | GET | `/v1/applications/{numero}` | sanctum | Détail candidature |
| 52 | PUT | `/v1/applications/{numero}` | sanctum | Modifier brouillon |
| 53 | POST | `/v1/applications/{numero}/submit` | sanctum | Soumettre |
| 54 | POST | `/v1/applications/{numero}/withdraw` | sanctum | Retirer |
| 55 | POST | `/v1/applications/{numero}/documents` | sanctum | Upload pièce |
| 56 | DELETE | `/v1/applications/{numero}/documents/{id}` | sanctum | Supprimer pièce |
| 57 | GET | `/v1/applications/{numero}/documents/{id}/download` | sanctum/comité | Télécharger pièce |
| 58 | GET | `/v1/applications/{numero}/recipisse` | sanctum | PDF récépissé |
| 59 | POST | `/v1/contact` | public | Formulaire contact |
| 60 | GET | `/v1/settings` | public | Paramètres globaux |
| 61 | GET | `/v1/health` | public | Healthcheck |
| 62 | GET | `/v1/sitemap.xml` | public | Sitemap XML |

**Total : 62 endpoints publics REST.** Le panneau admin Filament expose des routes propres non comptabilisées ici.

---

## Annexe — Génération des types TypeScript côté frontend

Le contrat ci-dessus doit être traduit en types TypeScript stricts pour les trois apps Next.js. Deux approches possibles :

**Option A — Types écrits à la main**, dans `frontend/lib/api/types.ts` (et équivalents pour `library/`, `candidature/`). Discipline requise pour rester en phase. Recommandé en V1 — plus simple, moins de friction de build.

**Option B — Génération automatique** depuis une description OpenAPI 3.1 que Laravel produirait via `dedoc/scramble` ou `vyuldashev/laravel-openapi`. À considérer en Phase II si la pression sur la cohérence augmente.

En V1, **on retient l'option A** : fichier `types.ts` co-écrit avec le développement de chaque endpoint, relu lors des PR. Le sous-agent `api-contract-reviewer` (à créer) pourra vérifier mécaniquement que tout endpoint utilisé côté Next.js a un type déclaré et que ces types correspondent à la doc présente.
