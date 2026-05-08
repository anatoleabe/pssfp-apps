# 2026-05-08 — Sprint S2 — PR C — Module 5 Applications + Référentiels + PDF récépissé

> Branche : `feat/m5-pr-c-applications`
> Périmètre : controllers `/v1/applications/*` + `/v1/reference/*` + génération PDF récépissé + page QR fallback
> Spec : `docs/specs/module-5-candidatures.md` v1.1
> Mise à jour : ADR-0005 (TTL token candidat 7j) déjà faite en PR B

## Endpoints livrés

Tous sous `/v1/applications/*` ou `/v1/reference/*`. Préfixe global `v1` géré dans bootstrap.

| Endpoint | Auth | Réponses |
|---|---|---|
| `GET /v1/applications/campaigns/current` | aucune | `{data: campagne|null}` |
| `GET /v1/applications/me` | `auth:sanctum` `ability:application:read` | 200 `CandidatureResource`, 404 sinon |
| `PUT /v1/applications/me` | `auth:sanctum` `ability:application:create,profile:write` | upsert idempotent, 200, 409 si soumise, 422 format |
| `POST /v1/applications/me/submit` | `auth:sanctum` `ability:application:submit` | 200 + URL signée, 422 si profil incomplet, 409 déjà soumis, idempotent |
| `GET /v1/applications/me/recipisse` | `auth:sanctum` `ability:application:read` | 302 → URL signée 30 min, 404 sinon |
| `GET /v1/c/{uuid}/qr` | aucune | HTML public minimal pour scan QR au comptoir |
| `GET /v1/reference/pays` | aucune | 200+ pays cache 24h |
| `GET /v1/reference/regions-cameroun` | aucune | 11 régions cache 24h |
| `GET /v1/reference/departements-cameroun?region=` | aucune | 58+1 départements filtrables cache 24h |
| `GET /v1/reference/specialites` | aucune | liste blanche V1 (config statique) |

## Décisions de PR (8 arbitrages + 4 ajouts intégrés)

### Arbitrages validés

- **A — `PUT /me` upsert** : le premier appel crée la `Candidature` postulant liée à l'auth user pour la campagne courante. Appels suivants = update partiel.
- **B — Validation laxiste à `PUT`, stricte à `submit`** : migration `extend_candidatures_for_draft` rend NULLABLE 21 colonnes profil. Restent NOT NULL : `campagne_id`, `phone_e164`, `phone_country`, `nom`, `prenom`, `date_naissance` + identifiants techniques (`uuid`, `numero_dossier`).
- **C — Assets PDF dans `backend/resources/images/pdf/`** : logo + entête copiés depuis `docs/migration-candidature/`.
- **D — Bucket MinIO `pssfp-candidatures`** : déjà créé par compose `minio-init`. Disk Laravel `minio_candidatures` ajouté à `config/filesystems.php` (3 disks séparés `minio_media`, `minio_documents`, `minio_candidatures`).
- **E — Idempotency-Key sur submit** : cache Redis 5 min, retour 200 du même résultat si UUID répété.
- **F — JsonResource enveloppe `{data: ...}`** : standard Laravel.
- **G — Cache 24h Redis sur référentiels** : tag `reference` pour purge globale future.
- **H — Découpage en une seule PR** : ~700 LOC tenu (limite haute).

### Ajouts intégrés

1. **Endpoint `/v1/c/{uuid}/qr`** : page Blade `c.qr-public` minimale, no-auth, masque le téléphone (`+237***4567`), affiche numéro dossier + statut + hash SHA256 du récépissé. Fait fonctionner le QR scan dès PR C sans attendre le frontend PR F.
2. **Audit log Spatie** sur la transition `candidature_submitted` : `causedBy(user)`, `performedOn(candidature)`, properties `{ip, pdf_path, pdf_hash}`.
3. **Validation cohérence métier au submit** :
   - `annee_diplome <= année courante`
   - `annee_diplome - année(date_naissance) >= 18`
   - `pays_residence == 'CM'` ⇒ `region` et `departement` obligatoires
   - `specialite` ∈ liste blanche `config/specialites.php`
4. **Permission `application.submit` distincte de `application.create`** : Spatie permission + ability Sanctum. La PR ajoute `application:submit` à `CANDIDAT_ABILITIES` du `AuthCandidatController` (modif PR B). V1 : le rôle candidat reçoit les deux. Phase II : un assistant USI pourra avoir `create` sans `submit`.

### Précisions intégrées

- **P-min-1 — Hash SHA256 du PDF binaire** (pas du Blade source). Auto-référencement : le PDF imprime son propre hash en pied de page via une seconde passe de génération (placeholder remplacé par le hash de la 1re passe).
- **P-min-2 — URL signée 30 min** retournée dans le body de `/me/submit` ET re-signée à chaque `/me/recipisse`.
- **P-min-3 — `confirmation_engagement: true` requis** au submit.
- **P-min-4 — Test ownership** : `MeUpdateTest` vérifie qu'un body `{numero_dossier, campagne_id, user_id, statut}` malicieux est ignoré silencieusement par `CandidatureService::updateDraft` (filtrage des champs systèmes).

## Schéma BDD étendu

Trois nouvelles migrations :

- `2026_05_15_120500_extend_candidatures_for_draft.php` — `ALTER COLUMN ... DROP NOT NULL` sur 21 colonnes profil pour permettre le wizard 4 étapes.
- `2026_05_15_120600_add_date_naissance_to_users.php` — corrige une lacune PR B : `date_naissance` était validée à register mais pas persistée. Désormais stockée sur `User` pour pré-remplir la `Candidature` à la première création.
- *(Note : le PR C ne touche pas à la migration `candidatures` initiale.)*

## Services backend ajoutés

- `CandidatureService` : `currentCampagne()`, `findForUser()`, `upsertForUser()`, `updateDraft()`, `checkSubmittable()`, `submit()` avec idempotency.
- `RecipisseService` : génération PDF dompdf + QR code SimpleQRCode + double passe hash auto-référencé + stockage MinIO + `signedUrl()` 30 min. Retiré le marqueur `final` pour permettre le mock dans les tests.

## Templates Blade

- `resources/views/pdf/candidature-recipisse.blade.php` : 2 pages A4 portrait, filigranes, charte PSSFP (violet `#6B2FA0`, or `#C9A227`), QR + hash.
- `resources/views/c/qr-public.blade.php` : page publique scan QR, masking phone, hash visible.

## JsonResources + FormRequests

- 5 JsonResources : `Candidature`, `CampagneCandidature` (sans `prefix_numero` interne), `Pays`, `RegionCameroun`, `DepartementCameroun`.
- 2 FormRequests : `UpdateCandidatureRequest` (validation laxiste avec `sometimes` partout, formats stricts), `SubmitCandidatureRequest` (juste `confirmation_engagement` car la validation profil + métier passe par `CandidatureService::checkSubmittable`).

## Tests Pest — 129 verts, 334 assertions

Nouveaux fichiers de tests PR C (41 nouveaux tests) :

| Suite | Tests |
|---|---|
| `Reference/ReferenceTest` | 6 — pays, regions, departements (filtre), specialites, cache HIT/MISS |
| `Applications/CurrentCampaignTest` | 4 — open / null / draft pas exposé / closed pas exposé |
| `Applications/MeUpdateTest` | 10 — happy path, partial update, 422 format, 409 si soumise, ownership anti-hack body, `/me` 404 si pas de candidature, `/me` 200 sinon |
| `Applications/SubmitTest` | 10 — happy path, 422 si incomplet, 422 si confirmation absente, 409 si déjà soumise, idempotency replay, règles métier (annee_diplome future, region CM, specialite whitelist), audit log écrit, 422 campagne fermée, ability `application:submit` requise |
| `Applications/RecipisseDownloadTest` | 3 — 302 redirect, 404 si pas de PDF, 401 unauth |
| `Applications/QrPublicTest` | 4 — page lisible no-auth, masking phone, 404 uuid inconnu, hash SHA256 visible |
| `Applications/RecipisseGenerationTest` | 3 — PDF généré + stocké minio_candidatures, hash 64 hex, path `{uuid}/recipisse.pdf` |

Plus les 88 tests PR A+B toujours verts.

## Modifications transverses

- `backend/config/filesystems.php` : 3 disks MinIO (`minio_media`, `minio_documents`, `minio_candidatures`).
- `backend/config/specialites.php` : liste blanche V1 des 5 spécialités. Sera remplacée par une table seedée quand Module 6 CMS arrivera.
- `backend/app/Models/User.php` : `date_naissance` ajouté aux fillable + casts, relation `candidatures()` HasMany.
- `backend/app/Http/Controllers/Api/Auth/AuthCandidatController.php` : `application:submit` ajouté à `CANDIDAT_ABILITIES`.
- Tests PR B mis à jour pour la nouvelle liste d'abilities (5 au lieu de 4).

## Hors périmètre — viendra en PRs suivantes

| PR | Périmètre |
|---|---|
| **D** | Filament `CandidatureResource` + widget quotas régionaux + bootstrap panel admin. |
| **E** | Frontend `/inscription` wizard 4 étapes + cascading PaysRegionDepartementSelect + pages publiques campagne. |
| **F** | Frontend `/login` + `/forgot-pin` + `/dossier` + `/dossier/suivi`. |
