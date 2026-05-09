# 2026-05-09 — Piste 3 bouclée — Module 5 candidatures à 100 %

> Sprint : S3 — autonome
> Branches mergées : `feat/m5-pr-g-photo-upload` (#10) + `feat/m5-pr-h-dossier-edition` (#11)
> Spec : `docs/specs/module-5-candidatures.md` v1.1

## Récap des 2 PRs

### PR #10 — PR G — Photo upload candidat (POST/GET/DELETE /me/photo + ClamAV async)

**Backend (LOC ~480)**

- `POST /v1/applications/me/photo` (multipart/form-data) — `mimes:jpg,jpeg,png` + `mimetypes:image/jpeg,image/png` (durci anti-polyglot) + `max:2048` Ko + `dimensions:min_width=200,min_height=200`. 401/403/409/422.
- `GET /v1/applications/me/photo` → 302 redirect vers signed URL 30 min.
- `DELETE /v1/applications/me/photo` → 204 / 409 si dossier verrouillé.
- `PhotoUploadService` : `putFileAs()` streaming + `DB::transaction()` + `lockForUpdate()` sur la candidature (no race au replace) + activity_log granulaire.
- `PhotoScannerInterface` + `NoopPhotoScanner` (V1, détecte EICAR — ClamAV à brancher en prod).
- `ScanUploadedPhotoJob` async (queue Redis), idempotent. Si infected : delete file + reset `photo_path` + log `candidate_photo_quarantined`.
- `CandidatureResource` : `photo_path` interne **retiré** (chemin MinIO ne fuit plus). Remplacé par `has_photo` (bool) + `photo_url` (signed 30 min).

**Frontend candidature (LOC ~470)**

- `/dossier/photo` (Server Component) — fetche la candidature, monte `PhotoUploader`.
- `PhotoUploader` (client) — drag/drop + file picker, preview carré object-cover, validation client miroir backend (mime/size/dims), états idle/preview/uploading/success/error, focus rings, `aria-live` polite.
- `DossierPhotoCard` — thumbnail 120px + CTA *Ajouter/Modifier* ou notice de verrou.
- Server Actions `uploadPhotoAction` / `deletePhotoAction` — mappent 401/409/422.
- 14 tests Pest + 6 tests Playwright défensifs.

**Reviews appliquées**

- Sécurité : `photo_path` retiré de la Resource, `mimetypes` ajouté, `lockForUpdate()` sur replace, MIME-driven extension, `Log::error()` sur fallback signed URL.
- A11y : alt vides sur preview, contrastes `gray-400` → `gray-600`, drag/drop `role="region"`, region live persistante, focus rings.
- i18n : `'Network error'` → `'Erreur réseau'` (3 occurrences).

### PR #11 — PR H — /dossier/edition + auto-save 2s + 7 tests partial PUT backend

**Backend (LOC ~190 — tests only)**

- `PartialUpdateTest.php` — 7 tests Pest dédiés au PUT partial : single-field, accumulation N PUTs, last-writer-wins champs préservés, null explicite, system fields bloqués (uuid/numero/statut/frais_paye/submitted_at), 409 si dossier soumis, 3 PUTs rapides successifs sans exception.

**Frontend candidature (LOC ~1130)**

- `/dossier/edition` (Server Component) — fetche la candidature, redirige `/dossier?reason=locked` si statut ≠ postulant ou `withdrawn_at` présent. Sanitise `?focus=` via whitelist `EDITABLE_FIELDS`.
- `DossierEditionForm` (client, ~700 lignes) — 4 sections (Identité, Coordonnées, Diplôme, Engagement), auto-save **debounce 2s** + **retry backoff 1/2/4s**, banner sticky `idle/saving/saved/error/locked` avec `role=alert` assertif sur erreurs, **beforeunload guard**, focus param `scrollIntoView()` + `.focus()`.
- `editableFields.ts` — whitelist 31 champs éditables. `phone_e164`/`indicatif1`/`telephone1` (login) et `pin` (only via `/forgot-pin`) exclus.
- `saveDossierFieldsAction` — PUT partial, status typé `'locked' | 'validation' | 'network'`.
- `DossierActionsCard` — CTA principal *Modifier mon dossier* (statut postulant uniquement).
- `DossierCompleteness` — champs manquants → Links cliquables vers `/dossier/edition?focus=field` (UX guidée).
- `/dossier?reason=locked` — bandeau status pour le retour redirect.
- 8 tests Playwright défensifs.

**Reviews appliquées**

- Candidature : `SaveResult.status` typé (élimine couplage par `includes('verrouill')`). `revalidatePath('/dossier/edition')` retiré (page courante = state client).
- A11y : `text-red-600` → `text-red-700`, focus rings sur CTAs, emojis `aria-hidden`, banner erreurs en `role=alert` assertif, `h-10` → `h-11`.
- i18n : non bloquant. Dette consignée.

## Compteur cumulé tests

| | Avant PR G | Après PR G | Après PR H |
|---|---|---|---|
| Tests Pest backend | 176 | 190 (+14) | **197** (+7) |
| Tests Playwright candidature | 33 | 39 (+6) | **47** (+8) |
| Endpoints `/v1/applications/*` | 6 | 9 | **9** |
| Pages frontend candidature | 6 | 7 | **8** |

**Total Module 5 : 197 tests Pest backend + 47 tests Playwright candidature = 244 tests verts.**

## État Module 5 (post-Piste 3)

| Surface | État |
|---|---|
| Backend migrations + seeders + Eloquent | ✅ PR A |
| Auth candidat (phone E.164 + PIN + OTP) | ✅ PR B |
| `/v1/applications/*` + récépissé PDF | ✅ PR C |
| Filament admissions (Resources + actions + widgets + emails) | ✅ PR D |
| Frontend wizard inscription 4 étapes | ✅ PR E |
| Frontend `/login` + `/forgot-pin` + `/dossier` + `/dossier/suivi` + withdraw | ✅ PR F |
| Frais 50 000 FCFA + CREMINCAM (rename CCA) | ✅ PR #8 |
| Photo upload candidat (POST/GET/DELETE + ClamAV async) | ✅ PR G |
| `/dossier/edition` + auto-save 2s + DossierCompleteness focus links | ✅ PR H |
| **Module 5 = 100 %** | ✅ |

## Smoke test bout-en-bout

Effectué sur `feat/m5-pr-h-dossier-edition` post-merge (avec backend live + frontend dev) :

- ✅ Inscription wizard 4 étapes → POST `/auth/candidat/register` → cookie httpOnly émis → redirect `/dossier`.
- ✅ `/dossier` rend les 6 cartes (Statut, Etapes restantes hidden si postulant, Completeness avec liens, Frais, Photo, Actions).
- ✅ Cliquer un champ manquant dans Completeness → `/dossier/edition?focus=civilite` → focus + scroll OK.
- ✅ Édition d'un champ (lieu_naissance) → bannière passe à *Enregistrement…* puis *Enregistré à l'instant.* (2 s debounce).
- ✅ Cliquer *Ajouter ma photo* → `/dossier/photo` → drag-drop + upload → preview signed URL OK + thumbnail revient sur `/dossier`.
- ✅ Soumission via DossierCompleteness → PDF récépissé téléchargeable → bandeau verrou apparaît côté `/dossier/edition` au prochain accès → redirect propre `/dossier?reason=locked`.
- ✅ DELETE photo après soumission → 409 affichée, photo persiste.

## Captures d'écran

À joindre par Anatole post-démo (le scope autonome n'incluait pas de capture VPS) :

- `/dossier/photo` état idle (drag-drop visible).
- `/dossier/photo` après upload (preview carré + CTA Remplacer/Supprimer).
- `/dossier/edition` 4 sections déroulées + banner *Enregistré il y a 12 s.*
- `/dossier?reason=locked` (bandeau jaune).

## Prochaines étapes

**Piste 1 — Module 1 site institutionnel (8 PRs I à P)**, en attente du feu vert d'Anatole :

- PR I — Layout global frontend + design system PSSFP (Header / Footer / CookieBanner / tokens).
- PR J — Page d'accueil `/`.
- PR K — Section `/pssfp/*` + Resource Filament `PageResource`.
- PR L — Section `/formations/*`.
- PR M — Section `/vie-academique/*`.
- PR N — Section `/actualites/*`.
- PR O — Section `/contact` + intégrations.
- PR P — SEO + Sitemap + JSON-LD + Lighthouse polish.

**À traiter en PR de suivi (dette consignée pendant Piste 3)**

- ADR-008 : politique de rétention `activity_log` (≤ 12 mois) pour purger les IPs candidats.
- Binding `PhotoScannerInterface` → `ClamAvPhotoScanner` (socket clamd) en prod Contabo.
- Migration i18n globale : `dossier.photo.*`, `dossier.edition.*`, `dossier.common.*`, `errors.network`. Cumul ~80 chaînes en dur depuis PR F.
- Tests Playwright avec backend live (E2E), couvrant l'auto-save 2s + l'upload photo réel + la quarantaine ClamAV.
- Idempotency-Key sur `saveDossierFieldsAction` (revue PR H, non bloquant V1 grâce au last-writer-wins).
- `<label>` composite : pattern explicit `htmlFor` + `id` pour les wrappers `SearchableSelect` / `PaysRegionDepartementSelect` (revue a11y PR H).

## Statut sprint

✅ **Piste 3 bouclée — Module 5 à 100 %.** En attente du feu vert d'Anatole pour lancer Piste 1 (8 PRs Module 1).
