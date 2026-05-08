# ADR-0002 — PostgreSQL 16 plutôt que MySQL 8 ou MongoDB

**Statut** : Accepté
**Date** : 2026-05-05
**Décideur** : M. ABE ETOUMOU Anatole — Chef USI
**Référence CDC** : CDC-PSSFP-WEB-2026-005 v5.0 §9.1 (option ouverte « MySQL 8 / PostgreSQL 16 ») ; CDC bibliothèque v2.0 §I.1 (préconise PostgreSQL 16)

## Contexte

Le CDC v5 laisse ouverte l'alternative MySQL 8 / PostgreSQL 16 sans trancher. Le CDC bibliothèque v2.0 préconise plus explicitement PostgreSQL 16. La question d'un store NoSQL (MongoDB) a également été posée par le Chef USI le 5 mai 2026.

Le projet manipule plusieurs catégories de données très structurées et fortement relationnelles : pages éditoriales, articles, catégories, tags, formations, spécialités, unités d'enseignement, promotions, enseignants, partenaires, événements, documents bibliothèque avec métadonnées, utilisateurs, rôles, candidatures avec pièces jointes, transactions de paiement (Phase II). Le volume cible est modeste — quelques milliers de pages éditoriales, ~500 documents bibliothèque, quelques centaines d'utilisateurs authentifiés en pic — mais la richesse des relations est élevée (jointures fréquentes, accès différencié par rôle, statistiques agrégées).

À cela s'ajoutent quelques besoins ponctuels de stockage flexible : tableau de débouchés par spécialité, métadonnées variables selon le type de document, configuration par site. Ces zones sont marginales (≤ 5% du modèle).

## Décision

**PostgreSQL 16** est retenu comme base de données unique pour le projet, partagée entre toutes les applications du mono-repo (frontend + backend + biblio + candidature) via le backend Laravel.

Configuration cible :

- Version : PostgreSQL 16.x (LTS jusqu'en novembre 2028).
- Encodage : UTF-8, locale `fr_FR.UTF-8`.
- Extensions activées : `pg_trgm` (recherche par trigrammes — fallback si Meilisearch indisponible), `unaccent` (normalisation accents pour recherche).
- Backup : `pg_dump` quotidien automatisé via `laravel-backup`, expédié vers MinIO local + S3 externe (Scaleway), rétention 30 jours, PRA testé semestriellement.

## Conséquences

### Positives

PostgreSQL 16 apporte trois avantages décisifs pour ce projet. **Premièrement**, son support JSONB indexé permet de stocker les zones flexibles (débouchés array, metadata variables) avec la même base que les zones strictes — pas besoin de double infrastructure SQL+NoSQL. **Deuxièmement**, ses capacités full-text natives (FTS + trigrammes) constituent un excellent fallback si Meilisearch tombe, garantissant la continuité du service biblio. **Troisièmement**, ses fonctions analytiques avancées (window functions, CTEs récursives) facilitent la production des rapports CAMES et des statistiques de tableau de bord exigées par le CDC biblio v2.0 §E.4.

S'ajoutent : robustesse ACID complète indispensable pour les transactions de paiement Phase II, gestion native des UUIDs pour les clés exposées en API, performance équivalente ou supérieure à MySQL 8 sur les charges en lecture intensives prévues, écosystème Laravel/Eloquent excellent (driver `pgsql` mature). Open source sans inquiétude de gouvernance (vs MySQL et son rachat Oracle).

### Négatives ou trade-offs

Le pool de compétences PostgreSQL au Cameroun est légèrement plus restreint que MySQL — différence en pratique négligeable pour des opérations Eloquent classiques, mais à anticiper si l'USI doit débugger directement en SQL. Quelques différences de syntaxe avec MySQL (LIMIT/OFFSET, types BOOLEAN strict, casing des identifiants) demandent un temps d'adaptation. L'hébergement mutualisé est moins universellement compatible que MySQL — non bloquant ici puisque le VPS Contabo nous donne le contrôle total.

### Neutres ou à surveiller

Le choix de PostgreSQL ne nous expose pas à une migration future vers MySQL : les modèles Eloquent abstraient l'essentiel et les migrations Laravel sont portables. Surveiller la consommation RAM de PostgreSQL sur le VPS Contabo (8 Go au CDC) — laisser au moins 2 Go pour PostgreSQL en charge nominale, alerter à 80% via Sentry/Uptime Kuma.

## Alternatives envisagées

**Alternative A — MySQL 8.** Solution la plus universelle et la plus connue de l'équipe USI. Rejetée : full-text moins performant que PostgreSQL pour la recherche biblio (même avec Meilisearch en frontal, on utilisera le FTS de la BDD pour les requêtes admin Filament), JSON moins bien indexé, fonctions analytiques moins riches. Différence tangible mais pas catastrophique — c'est l'avantage cumulé qui penche vers PostgreSQL.

**Alternative B — MongoDB.** Évoquée par le Chef USI le 5 mai 2026. Rejetée pour trois raisons décisives : (1) **Filament 3 est construit sur Eloquent ORM relationnel** ; le package communautaire `mongodb/laravel-mongodb` n'est pas officiellement supporté par Filament et casse régulièrement les fonctionnalités avancées (relations polymorphes, certains champs Forms), ce qui annule l'intérêt de Filament — pourtant central dans notre stack (cf. ADR-0004). (2) Notre modèle de données est intrinsèquement relationnel (Specialite ↔ UE ↔ Enseignants ↔ Promotions, Document ↔ Auteurs ↔ MotsCles ↔ AccessLevel) : 90% des requêtes seront des jointures, ce qui est l'opposé du sweet spot MongoDB. (3) Les bibliothèques numériques de référence (Koha, DSpace, Invenio) sont toutes en SQL pour cette raison.

**Alternative C — SQLite.** Rejetée : adapté au développement local mais inadapté à la production (single-writer, pas de réplication, sauvegardes plus fragiles).

**Alternative D — Couplage PostgreSQL + MongoDB.** Rejetée : double stack à opérer, double sauvegarde, double formation USI, gain inexistant pour notre volume de données.

## Références

- CDC v5 §9.1 « Stack technologique »
- CDC bibliothèque v2.0 §I.1 « Stack technologique »
- PostgreSQL 16 release notes (https://www.postgresql.org/docs/16/release-16.html)
- Discussion technique du 5 mai 2026 (Anatole / Claude) — comparaison PostgreSQL vs MongoDB pour notre périmètre
