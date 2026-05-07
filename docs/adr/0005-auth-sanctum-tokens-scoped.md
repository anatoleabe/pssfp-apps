# ADR-0005 — Authentification Laravel Sanctum + tokens scoped

**Statut** : Accepté
**Date** : 2026-05-05
**Décideur** : M. ABE ETOUMOU Anatole — Chef USI
**Référence CDC** : CDC-PSSFP-WEB-2026-005 v5.0 §9.4 « Sécurité » et §A.5 « Gestion des utilisateurs (biblio) » ; CDC bibliothèque v2.0 §E.2 et §I.2

## Contexte

Le projet introduit dès la Phase I plusieurs scénarios d'authentification distincts :

1. **Admin / éditeur / bibliothécaire / comité d'admission** — accès au CMS Filament sur `api.pssfp.net/admin`, sessions navigateur classiques, 2FA obligatoire.
2. **Auditeur connecté à la bibliothèque** — compte `@pssfp.net`, accès aux supports de cours de sa filière, favoris, espace personnel sur `bibliotheque.pssfp.net`.
3. **Enseignant** — compte `@pssfp.net` avec rôle élargi, dépôt de ressources pédagogiques.
4. **Candidat** (rapatrié Phase I) — création de compte avec email vérifié sur `candidature.pssfp.net`, suivi de dossier.
5. **Public** — non authentifié, accès aux contenus libres uniquement.

Phase II ajoutera l'**Espace Étudiant** (Module 4) avec SSO depuis Moodle FOAD (`foad.pssfp.net`). La solution choisie en Phase I doit donc être extensible vers le SSO sans refonte.

Le frontend est composé de trois applications Next.js distinctes (institutionnel, biblio, candidature) consommant la même API Laravel sur `api.pssfp.net` — l'auth doit fonctionner cross-app sans friction utilisateur.

## Décision

Architecture d'authentification à **deux pistes complémentaires** sous **Laravel Sanctum** :

**Piste 1 — Sessions Filament (admin)** : pour le panneau d'administration `api.pssfp.net/admin`, on utilise les sessions Laravel natives (cookies first-party avec `SameSite=Lax`, CSRF Laravel). 2FA TOTP obligatoire pour les rôles `admin`, `editor`, `librarian`, `admission_committee`. Cette piste reste cantonnée au domaine `api.pssfp.net` — pas de cross-domain.

**Piste 2 — Sanctum tokens scoped (utilisateurs frontend)** : pour les utilisateurs des trois apps Next.js (auditeur, enseignant, candidat), on utilise **Sanctum Personal Access Tokens** avec abilities (scopes) granulaires :

- `library:read` — lecture catalogue biblio public.
- `library:read:restricted` — accès supports de cours (auditeur sur sa filière).
- `library:write` — dépôt de ressources (enseignant).
- `library:favorites` — gestion favoris (auditeur, enseignant).
- `application:create` — création de candidature (candidat).
- `application:read` — consultation de son propre dossier (candidat).
- `profile:read` / `profile:write` — gestion de son profil.

Le token est obtenu via `POST /v1/auth/login` (email + mot de passe + éventuel TOTP), retourné en clair une seule fois, stocké côté Next.js dans un **cookie httpOnly Secure SameSite=Lax** émis par le backend Laravel (pas de localStorage — vulnérable XSS). Durée de vie 24h pour les rôles auditeur/candidat, 8h pour enseignant. Refresh token rotatif (cf. `tymon/jwt-auth` patterns mais implémenté nativement avec Sanctum).

**Politique de mots de passe** : minimum 12 caractères, complexité testée à l'inscription (zxcvbn score ≥ 3), rotation non imposée (recommandation NIST 2024). Hashage Argon2id (défaut Laravel 11).

**Vérification email** : Laravel Mail standard via le SMTP configuré (`mail.creawebhosting.org:465`), token signé temporel valable 60 min.

**Rate limiting** : 5 tentatives de login par 10 minutes par IP, 60 requêtes/minute publiques, 300/minute auditeur authentifié. Brute force monitoring via Laravel `events('Failed')` + Sentry.

**Préparation SSO Phase II** : la table `users` inclut dès maintenant un champ `external_id` (nullable, indexé) et `auth_provider` (enum: local, moodle_sso) pour permettre l'arrivée du SSO Moodle sans migration cassante.

## Conséquences

### Positives

Sanctum est natif Laravel — zéro dépendance externe à maintenir, documentation excellente, intégration immédiate avec Eloquent et Spatie Permission. Le système de scopes répond proprement à la matrice de droits du CDC biblio v2.0 §A.5 : un token auditeur ne peut pas appeler les endpoints réservés enseignants. La séparation cookies/tokens entre admin et frontend est une pratique de sécurité saine — un XSS sur le frontend ne donne pas accès au panneau admin. Les cookies httpOnly avec SameSite=Lax protègent contre les attaques CSRF et XSS dans la mesure du raisonnable. La structure prête pour SSO Moodle évite une migration de schéma douloureuse en Phase II.

### Négatives ou trade-offs

L'architecture est plus complexe qu'un simple « tout sessions » ou « tout JWT » — le développeur doit comprendre quand utiliser quel mécanisme. Mitigation : documenté dans `backend/CLAUDE.md` avec exemples par cas d'usage. La gestion du refresh token côté frontend Next.js demande un middleware dédié (intercepteur fetch qui détecte 401 et rafraîchit) — non trivial mais bien outillé. Pas de SSO entre les trois apps Next.js en Phase I : un utilisateur connecté sur `bibliotheque.pssfp.net` devra se reconnecter sur `candidature.pssfp.net`. Acceptable puisque les parcours utilisateurs sont disjoints (un candidat ne consulte pas les cours en ligne) ; un SSO cross-frontend pourra être ajouté en Phase II avec un cookie `Domain=.pssfp.net`.

### Neutres ou à surveiller

Surveiller l'usage réel des scopes : si plusieurs scopes sont systématiquement combinés, envisager des « rôles abilities » groupés. Surveiller la croissance de la table `personal_access_tokens` (purger les tokens expirés via cron quotidien). En cas de fuite suspectée, prévoir une commande artisan `auth:revoke-all-tokens` (déjà fournie par Sanctum).

## Alternatives envisagées

**Alternative A — Laravel Passport (OAuth2 complet).** Rejetée : nous ne sommes pas un fournisseur d'identité pour des applications tierces (pas d'OAuth2 client_credentials, authorization_code à émettre vers d'autres apps). Sanctum couvre 100% du besoin avec moins de complexité.

**Alternative B — JWT pur (firebase/php-jwt ou tymon/jwt-auth).** Rejetée : moins intégré à Laravel que Sanctum, pas de révocation native, gestion du refresh complexe à coder propre. Sanctum offre la même UX de tokens en gérant proprement le stockage en BDD et la révocation.

**Alternative C — Sessions Laravel partout (y compris frontend Next.js cross-domain).** Rejetée : nécessite de tout servir sous un même domaine ou cookies Domain partagés ; SSR Next.js complique la propagation des cookies aux Server Components ; moins « API-natif ».

**Alternative D — Auth0 / Clerk / Supabase Auth (SaaS).** Rejetées : dépendance à un fournisseur tiers (souveraineté, comme pour Algolia et Contentful), coût récurrent, complexité d'intégration avec Filament côté admin.

**Alternative E — Magic links (passwordless) en complément du mot de passe.** Initialement envisagée pour les candidats. **Rejetée le 5 mai 2026 par décision USI** : double chemin d'authentification à coder et à maintenir, formation utilisateur plus complexe, surface d'attaque accrue (deux mécanismes à sécuriser), bénéfice marginal au regard de la complexité ajoutée. Les candidats utiliseront uniquement **email + mot de passe + vérification d'email à l'inscription** comme tous les autres rôles. La récupération de mot de passe se fait via le lien temporel signé Laravel standard (60 min de validité).

## Références

- CDC v5 §9.4 « Sécurité » et §A.5 « Gestion des utilisateurs »
- CDC bibliothèque v2.0 §E.2 « Gestion des accès et authentification » et §I.2 « Sécurité »
- Laravel Sanctum documentation (https://laravel.com/docs/11.x/sanctum)
- OWASP Authentication Cheat Sheet 2024
- NIST SP 800-63B (recommandations modernes mots de passe)
