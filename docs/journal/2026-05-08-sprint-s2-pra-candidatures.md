# 2026-05-08 — Sprint S2 — PR A — Module 5 Candidatures

> Branche : `feat/m5-candidatures`
> Périmètre : migrations BDD + référentiels seedés + modèles Eloquent + service génération numéro
> Spec primaire : `docs/specs/module-5-candidatures.md` v1.1
> ADR auth : `docs/adr/0007-pin-6-chiffres-candidats.md`

## Contenu de la PR A

Première PR du sprint S2 (séquence A → F, 6 PRs au total). Elle pose la fondation BDD pure, sans aucun endpoint API, sans frontend, sans Filament.

### Migrations créées

1. `2026_05_15_120000_create_reference_tables.php` — `pays`, `regions_cameroun`, `departements_cameroun`.
2. `2026_05_15_120100_create_campagnes_candidature_table.php` — campagnes avec `prefix_numero`, `max_voeux=1` par défaut, statut enum-CHECK.
3. `2026_05_15_120200_create_candidatures_table.php` — table plate aligned spec v1.1 §M6 (50+ colonnes profil/identité/géographie/pédagogique/engagement/frais/PDF + statut workflow).

### Décisions de schéma

- **Identifiants** : `id` BIGSERIAL interne, `uuid` UUID exposé en API publique (`getRouteKeyName()`), `numero_dossier` au format `P{N}{YY}-{ID}` pour le tracking administratif.
- **Unicité phone** : `UNIQUE (campagne_id, phone_e164)` au lieu d'un UNIQUE global. Permet à un candidat de re-postuler à une campagne ultérieure (si refusé à P14, il peut tenter P15). Correction issue de la review du plan PR A.
- **FK ON DELETE** explicites :
  - `campagne_id` : RESTRICT (préserve l'historique).
  - `user_id` : SET NULL.
  - `nationalite`, `pays_origine`, `pays_residence` : RESTRICT (intégrité géographique).
  - `region`, `departement` : SET NULL (cas réel de réorganisation administrative camerounaise).
- **CHECK constraints Postgres** pour les enum métier : `statut`, `type_etude`, `premiere_langue`, `statut_actuel`. Plus robuste qu'une validation purement applicative.
- **`photo_path`** : chemin relatif dans le bucket MinIO `pssfp-candidatures` — jamais d'URL publique. Tout téléchargement passera par URL signée 30 min côté API (PR C).
- **`nationalite`** : citoyenneté ISO-2, distincte de `pays_residence`. Naming volontairement aligné sur l'usage français existant (système P13).

### Service `CandidatureNumeroService`

Génère le `numero_dossier` atomiquement via `pg_advisory_xact_lock(42, $campagne->id)` (namespace 42 = numéros candidatures, clé = id campagne, plus lisible qu'un crc32).

Calcul du suffixe via `MAX(SUBSTRING(numero_dossier FROM '[0-9]+$'))` — robuste à un éventuel hard-delete ultérieur (correction issue de la review : un `count()+1` reculerait après purge et collisionnerait avec un numéro existant).

Règle de contention : `Candidature::forceDelete()` interdit en prod sauf via commande artisan dédiée — à coder en PR C ou ultérieurement.

### Tests Pest

Quatre fichiers, 30 assertions au total :

- `CandidatureMigrationTest` — vérifie tables, colonnes, contraintes UNIQUE composite, CHECK constraints, FK ON DELETE corrects (lecture `pg_constraint`).
- `ReferenceSeedersTest` — vérifie le seed des 200+ pays, 11 régions (dont Z AUTRES), 58 départements + idempotence.
- `CandidatureNumeroServiceTest` — incrémentation, isolation entre campagnes, padding 3 chiffres, **soft-delete sans collision**, **advisory lock observable dans `pg_locks`**, contrainte composite phone+campagne. Pas de `pcntl_fork` (correction issue de la review : trop fragile en CI).
- `CandidatureModelTest` — UUID auto-généré, route key, relations, scopes, soft-delete, CHECK constraints.

### Infrastructure de test

`phpunit.xml` ne force plus `DB_CONNECTION=sqlite`. Les migrations utilisent des features Postgres (advisory lock, regex `SUBSTRING`, `CHECK` constraints) — donc tous les tests tournent maintenant sur Postgres. Création de `backend/.env.testing.example` avec les paramètres correspondant au runner CI. `RefreshDatabase` activé globalement sur le suite Feature dans `tests/Pest.php`.

Pour lancer les tests localement : `make dev` (démarre Postgres) + `cp backend/.env.testing.example backend/.env.testing` + `php artisan migrate --env=testing` + `./vendor/bin/pest`.

## Ce qui n'est PAS dans cette PR

- Endpoints API `/v1/applications/*` ni `/v1/reference/*` — PR C.
- Auth candidat phone+PIN — PR B.
- Génération PDF récépissé — PR C.
- Filament `CandidatureResource` + widget quotas — PR D.
- Frontend wizard inscription — PR E.
- Frontend login + dossier — PR F.

`docs/api-contract.md` reste sur la version pré-v1.1 ; il sera mis à jour en PR C en même temps que les controllers (les endpoints actuels documentés sont obsolètes par rapport à la spec v1.1, pas la peine de les corriger à blanc).

## Prochaine étape

Après merge de cette PR sur main : ouverture du plan PR B (auth candidat phone + PIN 6 chiffres + endpoints `/v1/auth/candidat/*` + `SmsService` stub `fake`).
