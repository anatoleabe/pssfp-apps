# Plan d'implémentation — Photo non bloquante à la soumission (module 5)

> **Pour agents d'exécution :** SOUS-SKILL REQUISE — utiliser `superpowers:subagent-driven-development` (recommandé) ou `superpowers:executing-plans` pour exécuter ce plan tâche par tâche. Les étapes utilisent la syntaxe case à cocher (`- [ ]`).

**Objectif :** Permettre à un candidat de soumettre sa candidature sans photo d'identité, et de déposer cette photo après soumission tant que la campagne est ouverte — sans invalider un seul des 82 dossiers déjà soumis ni un seul récépissé déjà imprimé.

**Architecture :** Aucune migration, aucune colonne. Trois règles métier changent de place. `checkSubmittable()` cesse de renvoyer l'erreur `photo`. `classifyDraft()` cesse de dériver le blocage photo des erreurs de `checkSubmittable()` et le lit directement sur `photo_path`, ce qui préserve le filtre admin et la relance SMS. `uploadPhoto()` accepte un dépôt après soumission uniquement lorsque `photo_path` est `null` (comblement, jamais remplacement). Le récépissé n'est pas régénéré automatiquement.

**Stack :** Laravel 11 / PHP 8.3 / PostgreSQL 16 / Pest côté backend · Next.js 14 App Router / TypeScript strict / next-intl / Playwright côté candidature · Filament 3 côté admin.

**ADR de référence :** `docs/adr/0009-photo-non-bloquante-a-la-soumission.md`

**Branche :** `feat/m5-photo-non-bloquante`

## Contraintes globales

- Site **en production**, campagne P14 ouverte, clôture imminente. Aucun dossier existant ne doit être modifié, invalidé ou reclassé silencieusement.
- **Aucune migration** dans ce lot. Si une colonne semble nécessaire, s'arrêter et rouvrir l'ADR.
- **Le récépissé n'est jamais régénéré automatiquement.** Le hash `recipisse_hash_sha256` d'un dossier déjà soumis ne doit changer que par l'action Filament manuelle existante.
- **Aucun texte en dur dans le JSX** — tout passe par `next-intl` (`t('clé')`), `messages/fr.json` **et** `messages/en.json`.
- **Immutabilité** : jamais de mutation en place d'un tableau ou d'un objet d'état.
- **Commits Conventional Commits**, un par tâche minimum.
- WCAG 2.1 AA : tout nouveau bandeau porte un rôle ARIA correct et reste atteignable au clavier.
- Tests verts obligatoires avant passage à la tâche suivante : `cd backend && ./vendor/bin/pest` et `cd candidature && pnpm test`.

## Structure de fichiers

**Backend — modifiés**

| Fichier | Responsabilité |
|---|---|
| `backend/app/Services/CandidatureService.php` | `checkSubmittable()` sans photo ; `classifyDraft()` sur `photo_path` |
| `backend/app/Http/Controllers/Api/Applications/CandidatureController.php` | `uploadPhoto()` ouvert après soumission si `photo_path` null |
| `backend/app/Filament/Resources/CandidatureResource.php` | colonne + filtre « soumis sans photo » |

**Backend — tests**

| Fichier | Responsabilité |
|---|---|
| `backend/tests/Feature/Applications/SubmitTest.php` | le 422 photo devient un 200 |
| `backend/tests/Feature/Applications/PhotoUploadTest.php` | dépôt tardif autorisé, remplacement refusé |
| `backend/tests/Feature/Applications/RelanceBrouillonsTest.php` | segment `DRAFT_PHOTO_ONLY` préservé |
| `backend/tests/Feature/Filament/CandidatureResourceTest.php` | filtre « soumis sans photo » |

**Candidature — modifiés**

| Fichier | Responsabilité |
|---|---|
| `candidature/lib/validation/submittable.ts` | photo retirée des bloquants |
| `candidature/components/dossier/DossierCompleteness.tsx` | photo « recommandée » ; écran soumis avec rappel photo |
| `candidature/messages/fr.json` + `en.json` | libellés |

---

### Tâche 1 : la photo ne bloque plus la soumission (backend)

**Fichiers :**
- Modifier : `backend/app/Services/CandidatureService.php:215-221`
- Test : `backend/tests/Feature/Applications/SubmitTest.php:147-155`

**Interfaces :**
- Consomme : rien.
- Produit : `checkSubmittable(Candidature): array` ne contient plus jamais la clé `photo`.

- [ ] **Étape 1 : retourner le test existant qui verrouille l'ancien comportement**

Dans `backend/tests/Feature/Applications/SubmitTest.php`, remplacer le test `it('returns 422 when the required identity photo is missing')` par :

```php
it('submits without an identity photo and marks the dossier as candidat', function (): void {
    $user = completeCandidature();
    Candidature::where('user_id', $user->id)->update(['photo_path' => null]);

    $response = $this->withToken(tokenFor($user))
        ->postJson('/v1/applications/me/submit', ['confirmation_engagement' => true]);

    $response->assertStatus(200);
    expect(Candidature::where('user_id', $user->id)->first()->statut)
        ->toBe(Candidature::STATUT_CANDIDAT);
});
```

> Si les helpers `completeCandidature()` / `tokenFor()` portent d'autres noms dans ce fichier, reprendre ceux réellement utilisés par les tests voisins — ne pas en inventer.

- [ ] **Étape 2 : lancer le test, vérifier qu'il échoue**

Run : `cd backend && ./vendor/bin/pest tests/Feature/Applications/SubmitTest.php --filter="without an identity photo"`
Attendu : FAIL — `Expected response status code 200 but received 422`.

- [ ] **Étape 3 : retirer la photo de checkSubmittable**

Dans `backend/app/Services/CandidatureService.php`, supprimer intégralement ce bloc en tête de `checkSubmittable()` :

```php
        if (empty($candidature->photo_path)) {
            $errors['photo'] = 'La photo d\'identité est obligatoire pour soumettre la candidature.';
        }
```

Et compléter le commentaire de méthode pour documenter la règle :

```php
    /**
     * Règles bloquant la soumission.
     *
     * La photo d'identité n'en fait PAS partie depuis l'ADR-0009 : elle reste
     * obligatoire pour la recevabilité administrative du dossier, mais ne
     * retient plus 47 dossiers par ailleurs complets. Son absence se lit via
     * `classifyDraft()` et la colonne admin « soumis sans photo ».
     */
```

- [ ] **Étape 4 : relancer le test, vérifier qu'il passe**

Run : `cd backend && ./vendor/bin/pest tests/Feature/Applications/SubmitTest.php`
Attendu : PASS sur les 14 tests du fichier.

- [ ] **Étape 5 : commit**

```bash
git add backend/app/Services/CandidatureService.php backend/tests/Feature/Applications/SubmitTest.php
git commit -m "feat(candidature): la photo ne bloque plus la soumission (ADR-0009)"
```

---

### Tâche 2 : préserver le segment « bloqués par la photo »

**Fichiers :**
- Modifier : `backend/app/Services/CandidatureService.php:166-175`
- Test : `backend/tests/Feature/Applications/RelanceBrouillonsTest.php`

**Interfaces :**
- Consomme : `checkSubmittable()` de la tâche 1, désormais sans clé `photo`.
- Produit : `classifyDraft(Candidature): string` renvoyant toujours l'une des trois constantes `DRAFT_READY` / `DRAFT_PHOTO_ONLY` / `DRAFT_OTHER`, avec `DRAFT_PHOTO_ONLY` désormais fondé sur `photo_path`.

**Pourquoi cette tâche existe :** `classifyDraft()` dérivait le bucket photo de `array_keys($errors) === ['photo']`. La tâche 1 ayant supprimé cette clé, les 47 dossiers basculeraient en `DRAFT_READY` et feraient disparaître le filtre Filament « Bloqués par la photo », le widget d'avancement (`AvancementCampagneWidget:79`) et le ciblage de la relance SMS (`RelanceCandidatureService:55`, `RelancerBrouillonsCandidature:156`).

- [ ] **Étape 1 : écrire le test de non-régression du classement**

Ajouter à `backend/tests/Feature/Applications/RelanceBrouillonsTest.php` :

```php
it('classe un brouillon complet sans photo en DRAFT_PHOTO_ONLY', function (): void {
    $user = completeCandidature();
    $candidature = Candidature::where('user_id', $user->id)->first();
    $candidature->update(['photo_path' => null]);

    expect(app(CandidatureService::class)->classifyDraft($candidature->refresh()))
        ->toBe(CandidatureService::DRAFT_PHOTO_ONLY);
});

it('classe un brouillon complet avec photo en DRAFT_READY', function (): void {
    $user = completeCandidature();
    $candidature = Candidature::where('user_id', $user->id)->first();

    expect(app(CandidatureService::class)->classifyDraft($candidature))
        ->toBe(CandidatureService::DRAFT_READY);
});
```

- [ ] **Étape 2 : lancer, vérifier l'échec du premier test**

Run : `cd backend && ./vendor/bin/pest tests/Feature/Applications/RelanceBrouillonsTest.php --filter="DRAFT_PHOTO_ONLY"`
Attendu : FAIL — obtient `ready` au lieu de `photo_only`.

- [ ] **Étape 3 : classer sur photo_path**

Dans `backend/app/Services/CandidatureService.php`, remplacer le corps de `classifyDraft()` :

```php
    public function classifyDraft(Candidature $candidature): string
    {
        $errors = $this->checkSubmittable($candidature);

        if ($errors !== []) {
            return self::DRAFT_OTHER;
        }

        // La photo ne figure plus dans checkSubmittable (ADR-0009) : elle se
        // lit directement, sinon le segment « bloqués par la photo » — filtre
        // admin, widget d'avancement, ciblage des relances — disparaîtrait.
        return empty($candidature->photo_path) ? self::DRAFT_PHOTO_ONLY : self::DRAFT_READY;
    }
```

- [ ] **Étape 4 : relancer la suite complète du backend**

Run : `cd backend && ./vendor/bin/pest`
Attendu : tous verts. Surveiller particulièrement `RelanceBrouillonsTest`, `NotificationCandidatsTest` et `Filament/CandidatureResourceTest`.

- [ ] **Étape 5 : commit**

```bash
git add backend/app/Services/CandidatureService.php backend/tests/Feature/Applications/RelanceBrouillonsTest.php
git commit -m "fix(candidature): conserver le segment photo apres son retrait de checkSubmittable"
```

---

### Tâche 3 : dépôt de la photo après soumission

**Fichiers :**
- Modifier : `backend/app/Http/Controllers/Api/Applications/CandidatureController.php:167-192`
- Test : `backend/tests/Feature/Applications/PhotoUploadTest.php:133-141`

**Interfaces :**
- Consomme : `Candidature::STATUT_POSTULANT`, `Candidature::STATUT_CANDIDAT`.
- Produit : `POST /v1/applications/me/photo` accepte 201 après soumission si `photo_path` est `null` ; 409 sinon.

- [ ] **Étape 1 : écrire les deux tests**

Dans `backend/tests/Feature/Applications/PhotoUploadTest.php`, remplacer le test `it('returns 409 when uploading after submission (statut != postulant)')` par ce couple :

```php
it('accepte le depot de la photo apres soumission quand elle manque', function (): void {
    $user = completeCandidature();
    $candidature = Candidature::where('user_id', $user->id)->first();
    $candidature->update(['statut' => Candidature::STATUT_CANDIDAT, 'photo_path' => null]);

    $response = $this->withToken(tokenFor($user))->postJson('/v1/applications/me/photo', [
        'photo' => UploadedFile::fake()->image('photo.jpg', 400, 400),
    ]);

    $response->assertStatus(201);
    expect($candidature->refresh()->photo_path)->not->toBeNull();
});

it('refuse de remplacer une photo existante apres soumission', function (): void {
    $user = completeCandidature();
    $candidature = Candidature::where('user_id', $user->id)->first();
    $candidature->update([
        'statut' => Candidature::STATUT_CANDIDAT,
        'photo_path' => 'candidat-photos/test/photo.jpg',
    ]);

    $response = $this->withToken(tokenFor($user))->postJson('/v1/applications/me/photo', [
        'photo' => UploadedFile::fake()->image('autre.jpg', 400, 400),
    ]);

    $response->assertStatus(409);
    expect($candidature->refresh()->photo_path)->toBe('candidat-photos/test/photo.jpg');
});
```

- [ ] **Étape 2 : lancer, vérifier l'échec du premier**

Run : `cd backend && ./vendor/bin/pest tests/Feature/Applications/PhotoUploadTest.php --filter="apres soumission"`
Attendu : le test de dépôt FAIL en 409 ; celui de remplacement PASS déjà.

- [ ] **Étape 3 : ouvrir le dépôt de comblement**

Dans `backend/app/Http/Controllers/Api/Applications/CandidatureController.php`, méthode `uploadPhoto()`, remplacer le garde :

```php
        if ($candidature->statut !== Candidature::STATUT_POSTULANT) {
            return response()->json([
                'message' => 'Le dossier est verrouillé : la photo ne peut plus être modifiée.',
            ], Response::HTTP_CONFLICT);
        }
```

par :

```php
        // ADR-0009 : après soumission, la photo peut encore être DÉPOSÉE si
        // elle manque — jamais REMPLACÉE. Substituer une pièce sur un dossier
        // déjà certifié ouvrirait un trou dans la certification.
        if ($candidature->statut !== Candidature::STATUT_POSTULANT
            && $candidature->photo_path !== null) {
            return response()->json([
                'message' => 'Le dossier est verrouillé : la photo ne peut plus être remplacée.',
            ], Response::HTTP_CONFLICT);
        }
```

> Ne pas toucher à `deletePhoto()` : la suppression après soumission reste interdite.

- [ ] **Étape 4 : relancer le fichier de test entier**

Run : `cd backend && ./vendor/bin/pest tests/Feature/Applications/PhotoUploadTest.php`
Attendu : tous verts, y compris `DELETE /me/photo refuses with 409 once the dossier is locked`.

- [ ] **Étape 5 : commit**

```bash
git add backend/app/Http/Controllers/Api/Applications/CandidatureController.php backend/tests/Feature/Applications/PhotoUploadTest.php
git commit -m "feat(candidature): autoriser le depot tardif de la photo apres soumission"
```

---

### Tâche 4 : le portail cesse de bloquer le bouton de soumission

**Fichiers :**
- Modifier : `candidature/lib/validation/submittable.ts:52-54`
- Modifier : `candidature/components/dossier/DossierCompleteness.tsx:49`, `:119-122`, `:181-185`
- Modifier : `candidature/messages/fr.json:561-563`, `candidature/messages/en.json:561-563`

**Interfaces :**
- Consomme : `MyCandidature.has_photo` (inchangé côté API).
- Produit : `checkSubmittable(c)` côté TS ne pousse plus `'photo'` dans `missing`.

- [ ] **Étape 1 : retirer la photo des bloquants côté TS**

Dans `candidature/lib/validation/submittable.ts`, supprimer ce bloc :

```ts
  if (!c.has_photo) {
    missing.push('photo');
    errors.photo = "La photo d'identité est obligatoire pour soumettre la candidature.";
  }
```

- [ ] **Étape 2 : délier le bouton de la photo**

Dans `candidature/components/dossier/DossierCompleteness.tsx`, ligne 49, remplacer :

```tsx
  const canSubmit = result.ok && candidature.has_photo && !isAlreadySubmitted && candidature.withdrawn_at === null;
```

par :

```tsx
  // ADR-0009 : la photo n'est plus une condition de soumission — elle reste
  // recommandée et se dépose aussi après coup.
  const canSubmit = result.ok && !isAlreadySubmitted && candidature.withdrawn_at === null;
```

Puis, lignes 119-122, passer la photo en recommandée dans la checklist :

```tsx
        <li className={candidature.has_photo ? 'text-emerald-800' : 'text-amber-900'}>
          <span aria-hidden="true">{candidature.has_photo ? '☑' : '☐'} </span>
          {tc('photoRecommended')}
        </li>
```

Enfin, lignes 181-185, remplacer le message sous le bouton :

```tsx
      {!candidature.has_photo && (
        <p className="mt-2 text-xs text-amber-900" role="status">
          {tc('photoStillNeeded')}
        </p>
      )}
```

- [ ] **Étape 3 : libellés FR et EN**

Dans `candidature/messages/fr.json`, sous `dossier.checklist`, remplacer `photoRequired` et `addPhotoToSubmit` par :

```json
      "photoRecommended": "Photo d'identité — à fournir",
      "photoStillNeeded": "Vous pouvez soumettre sans photo, mais elle restera à déposer pour que votre dossier soit recevable.",
```

Dans `candidature/messages/en.json`, aux mêmes emplacements :

```json
      "photoRecommended": "Passport photograph — to be provided",
      "photoStillNeeded": "You may submit without a photograph, but it will still be required for your application to be admissible.",
```

Vérifier qu'aucune référence aux anciennes clés ne subsiste :

Run : `cd candidature && grep -rn "photoRequired\|addPhotoToSubmit" components/ app/ lib/ tests/`
Attendu : aucun résultat.

- [ ] **Étape 4 : vérifier types, lint et tests**

Run : `cd candidature && pnpm typecheck && pnpm lint && pnpm test`
Attendu : typecheck et lint silencieux, 99+ tests Playwright verts.

- [ ] **Étape 5 : commit**

```bash
git add candidature/lib/validation/submittable.ts candidature/components/dossier/DossierCompleteness.tsx candidature/messages/fr.json candidature/messages/en.json
git commit -m "feat(candidature): portail - la photo devient recommandee et non bloquante"
```

---

### Tâche 5 : rappel et dépôt de la photo sur un dossier déjà soumis

**Fichiers :**
- Modifier : `candidature/components/dossier/DossierCompleteness.tsx:95-107` (branche `isAlreadySubmitted`)
- Modifier : `candidature/messages/fr.json`, `candidature/messages/en.json`
- Test : `candidature/tests/playwright/dossier-photo.spec.ts`

**Interfaces :**
- Consomme : `candidature.has_photo`, composant `PhotoUploader` (`candidature/components/PhotoUploader/index.tsx`).
- Produit : sur un dossier soumis sans photo, un bandeau `data-testid="submitted-photo-reminder"` et un lien vers `/dossier/pieces`.

**Pourquoi :** sans cet écran, les candidats qui soumettent sans photo n'ont ni moyen ni incitation à la fournir ensuite — on déplacerait le problème au lieu de le résoudre.

- [ ] **Étape 1 : afficher le rappel sur l'écran « soumis »**

Dans `candidature/components/dossier/DossierCompleteness.tsx`, remplacer la branche `if (isAlreadySubmitted)` par :

```tsx
  if (isAlreadySubmitted) {
    return (
      <section
        aria-labelledby="completeness-heading"
        className="rounded-lg border border-emerald-200 bg-emerald-50 p-6"
      >
        <h2 id="completeness-heading" className="font-heading text-lg font-bold text-emerald-800">
          {t('completeness.submittedTitle')}
        </h2>
        <p className="mt-2 text-sm text-emerald-900">{t('completeness.submittedBody')}</p>

        {!candidature.has_photo && (
          <div
            role="status"
            data-testid="submitted-photo-reminder"
            className="mt-4 rounded-md border border-amber-300 bg-amber-50 p-4"
          >
            <p className="text-sm font-semibold text-amber-900">{tc('submittedPhotoTitle')}</p>
            <p className="mt-1 text-sm text-amber-900">{tc('submittedPhotoBody')}</p>
            <Link
              href="/dossier/pieces"
              className="mt-3 inline-flex h-10 items-center rounded-md bg-[#4A2E67] px-4 text-sm font-semibold text-white hover:bg-[#3A2452] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67] focus-visible:ring-offset-2"
            >
              {tc('submittedPhotoCta')}
            </Link>
          </div>
        )}
      </section>
    );
  }
```

- [ ] **Étape 2 : libellés FR et EN**

`candidature/messages/fr.json`, sous `dossier.checklist` :

```json
      "submittedPhotoTitle": "Il vous reste à déposer votre photo d'identité",
      "submittedPhotoBody": "Votre candidature est bien enregistrée. Votre photo reste nécessaire pour que votre dossier soit recevable — vous pouvez la déposer dès maintenant.",
      "submittedPhotoCta": "Déposer ma photo",
```

`candidature/messages/en.json` :

```json
      "submittedPhotoTitle": "Your passport photograph is still needed",
      "submittedPhotoBody": "Your application has been recorded. Your photograph is still required for it to be admissible — you can upload it now.",
      "submittedPhotoCta": "Upload my photograph",
```

- [ ] **Étape 3 : garder l'uploader actif après soumission**

Run : `cd candidature && grep -n "statut\|has_photo\|disabled" components/PhotoUploader/index.tsx "app/[locale]/dossier/pieces/page.tsx"`

Si l'uploader est désactivé sur la base du statut, retirer cette condition **pour le seul cas `has_photo === false`** : une photo manquante doit rester déposable ; une photo déjà présente ne doit pas être remplaçable (l'API renvoie 409, l'UI ne doit donc pas proposer l'action).

- [ ] **Étape 4 : test Playwright du bandeau**

Ajouter à `candidature/tests/playwright/dossier-photo.spec.ts` un test gardé sur backend live, sur le modèle de `auth-session-live.spec.ts` (`test.skip(!API_URL, ...)`), qui inscrit un candidat, complète son dossier sans photo, soumet, ouvre `/dossier`, puis vérifie :

```ts
await expect(page.getByTestId('submitted-photo-reminder')).toBeVisible();
await expect(page.getByRole('link', { name: /Déposer ma photo/i })).toBeVisible();
```

- [ ] **Étape 5 : vérifier et committer**

Run : `cd candidature && pnpm typecheck && pnpm lint && pnpm test`
Attendu : tout vert.

```bash
git add candidature/components/dossier/DossierCompleteness.tsx candidature/messages/fr.json candidature/messages/en.json candidature/tests/playwright/dossier-photo.spec.ts
git commit -m "feat(candidature): rappel et depot de la photo sur un dossier deja soumis"
```

---

### Tâche 6 : isoler les dossiers soumis sans photo côté admin

**Fichiers :**
- Modifier : `backend/app/Filament/Resources/CandidatureResource.php` (colonnes + filtres de la table)
- Test : `backend/tests/Feature/Filament/CandidatureResourceTest.php`

**Interfaces :**
- Consomme : `Candidature::photo_path`, `Candidature::STATUT_CANDIDAT`.
- Produit : un filtre nommé `soumis_sans_photo` sur la table des candidatures.

**Pourquoi :** l'ADR-0009 transfère la charge du candidat vers la scolarité. Sans ce filtre, la scolarité n'a aucun moyen de voir qui relancer.

- [ ] **Étape 1 : écrire le test du filtre**

Ajouter à `backend/tests/Feature/Filament/CandidatureResourceTest.php` :

```php
it('filtre les dossiers soumis sans photo', function (): void {
    $avec = Candidature::factory()->create([
        'statut' => Candidature::STATUT_CANDIDAT,
        'submitted_at' => now(),
        'photo_path' => 'candidat-photos/x/photo.jpg',
    ]);
    $sans = Candidature::factory()->create([
        'statut' => Candidature::STATUT_CANDIDAT,
        'submitted_at' => now(),
        'photo_path' => null,
    ]);

    livewire(\App\Filament\Resources\CandidatureResource\Pages\ListCandidatures::class)
        ->filterTable('soumis_sans_photo')
        ->assertCanSeeTableRecords([$sans])
        ->assertCanNotSeeTableRecords([$avec]);
});
```

> Reprendre la classe de page et le helper `livewire()` réellement employés par les tests voisins de ce fichier.

- [ ] **Étape 2 : lancer, vérifier l'échec**

Run : `cd backend && ./vendor/bin/pest tests/Feature/Filament/CandidatureResourceTest.php --filter="soumis sans photo"`
Attendu : FAIL — filtre inconnu.

- [ ] **Étape 3 : ajouter le filtre et la colonne**

Dans `backend/app/Filament/Resources/CandidatureResource.php`, ajouter au tableau `->filters([...])` :

```php
                    Tables\Filters\Filter::make('soumis_sans_photo')
                        ->label('Soumis sans photo')
                        ->query(fn (Builder $query): Builder => $query
                            ->whereNotNull('submitted_at')
                            ->whereNull('photo_path'))
                        ->toggle(),
```

Et au tableau `->columns([...])`, une colonne lisible d'un coup d'œil :

```php
                    Tables\Columns\IconColumn::make('photo_path')
                        ->label('Photo')
                        ->boolean()
                        ->getStateUsing(fn ($record): bool => $record->photo_path !== null)
                        ->trueIcon('heroicon-o-check-circle')
                        ->falseIcon('heroicon-o-exclamation-triangle')
                        ->falseColor('warning')
                        ->toggleable(),
```

- [ ] **Étape 4 : relancer toute la suite backend**

Run : `cd backend && ./vendor/bin/pest`
Attendu : tout vert.

- [ ] **Étape 5 : commit**

```bash
git add backend/app/Filament/Resources/CandidatureResource.php backend/tests/Feature/Filament/CandidatureResourceTest.php
git commit -m "feat(admin): filtre et colonne pour les dossiers soumis sans photo"
```

---

### Tâche 7 : documentation, vérification globale et déploiement

**Fichiers :**
- Modifier : la spec module 5 en vigueur sous `docs/specs/` — section règles de soumission

- [ ] **Étape 1 : mettre la spec à jour**

Dans la spec du module 5, remplacer toute mention de la photo comme condition de soumission par un renvoi explicite : « Photo d'identité : obligatoire pour la recevabilité, non bloquante à la soumission — cf. ADR-0009. »

- [ ] **Étape 2 : vérification complète avant PR**

```bash
cd backend && ./vendor/bin/pest
cd ../candidature && pnpm typecheck && pnpm lint && pnpm build && pnpm test
```

Attendu : quatre commandes vertes. Ne pas poursuivre sinon.

- [ ] **Étape 3 : revue par sous-agents**

Lancer en parallèle `candidature-reviewer` (ownership, PII, idempotence) et `a11y-reviewer` (bandeau photo, contraste ambre sur ambre, navigation clavier) sur le diff complet de la branche. Traiter tout point CRITICAL ou HIGH avant la PR.

- [ ] **Étape 4 : commit et push**

```bash
git add docs/
git commit -m "docs(module-5): photo non bloquante a la soumission (ADR-0009)"
git push -u origin feat/m5-photo-non-bloquante
```

- [ ] **Étape 5 : déploiement**

Backend **et** candidature sont modifiés : `composer install`, `artisan optimize` et le rebuild Next.js sont tous nécessaires. Aucune migration.

```bash
ssh open-claw-codabe
cd /var/www/pssfp/app && sudo -u pssfp -H git pull --ff-only origin main
cd backend && sudo -u pssfp -H composer install --no-dev --optimize-autoloader && sudo -u pssfp -H php artisan optimize
cd ../candidature && sudo -u pssfp -H pnpm build
sudo -u pssfp -H pm2 reload pssfp-candidature --update-env
sudo systemctl restart pssfp-queue.service
```

Puis smoke test des pages candidature et de l'API, et vérification dans l'admin que le filtre « Soumis sans photo » répond.

- [ ] **Étape 6 : relance des deux segments**

Une fois le déploiement vérifié, depuis l'admin Filament :
- segment « Bloqués par la photo » (**47 dossiers**) — message : leur dossier est complet, ils peuvent désormais soumettre immédiatement et fournir la photo ensuite ;
- postulants complets **avec** photo non soumis (**37 dossiers**) — message : le problème technique de soumission est corrigé, ils peuvent réessayer.

---

## Ce que ce plan ne fait pas

- **Aucune régénération automatique du récépissé** au dépôt tardif de la photo (cf. ADR-0009, décision 4). Le rattrapage reste l'action Filament manuelle existante.
- **Aucune migration**, aucune colonne ajoutée.
- **Aucun remplacement de photo** après soumission.
- **Aucune optimisation du double rendu DomPDF** (2,90 s des 3,93 s de génération du récépissé). C'est le vrai levier de latence restant, mais il touche au schéma d'auto-référence du hash et mérite son propre ADR.

---

## Journal d'exécution — 2026-09-11

Plan exécuté intégralement et déployé en production. Commits `0560046` → `3034005`,
en plus de `a19a68a` (correctif timeout + modales mobiles).

### Fait

Les 7 tâches, plus les correctifs issus des deux revues.

Trois défauts ont été trouvés **pendant** l'exécution, absents du plan initial :

1. **`DossierPhotoCard` non mis à jour** — affichait « Photo verrouillée — dossier
   déposé », sans CTA, dans la même grille que le rappel « déposez-la maintenant ».
   Le plan n'avait recensé que `PhotoUploader` : ce composant-ci ne consomme pas
   `PhotoUploader` et était passé sous le radar du relevé de fichiers.
2. **Course concurrente sur le dépôt tardif** — `PhotoUploadService` vérifiait
   l'absence de photo hors verrou. Deux requêtes simultanées écrasaient la photo
   déposée. Vérification refaite sous `lockForUpdate`.
3. **Dossiers retirés et décidés** — la tolérance de comblement s'appliquait aussi
   aux dossiers `withdrawn`, `accepte` et `refuse`. Garde passé en liste blanche.

### Vérifié en production

- 490 tests Pest, 99 Playwright, typecheck, lint, Pint, build : verts.
- Toutes les surfaces publiques + `/admin` en HTTP 200.
- Règle métier active : `classifyDraft` renvoie bien **47** dossiers en
  `DRAFT_PHOTO_ONLY` — le segment admin et le ciblage des relances sont intacts.
- `php-fpm` : `pm.max_children` 5 → 20.

### Non fait — à traiter

**Relance des deux segments (tâche 7 étape 6).** Non déclenchée : envoyer des
SMS et e-mails à 84 personnes réelles est une action sortante irréversible, elle
demande un feu vert explicite. Segments prêts dans l'admin : « Bloqués par la
photo » (47) et postulants complets avec photo non soumis (37).

**Reliquats identifiés par les revues, hors périmètre ADR-0009 :**

- `submit()`, `updateDraft()` et `uploadDocument()` ignorent `withdrawn_at` :
  `withdraw()` ne pose que la date et laisse `statut = postulant`, donc l'API
  accepte encore une soumission sur un dossier retiré. Le portail bloque, pas
  l'API. **Pré-existant**, mais c'est le trou symétrique de celui fermé sur
  `uploadPhoto` — et il produirait des dossiers `submitted + withdrawn` que le
  filtre « Soumis sans photo » remonterait à la scolarité.
- Les deux modales (`DossierCompleteness`, `WithdrawDialog`) déclarent
  `aria-modal="true"` sans piège de focus, sans fermeture par `Échap` et sans
  restauration du focus. Pré-existant. Le `Dialog` de `@pssfp/ui` réglerait les
  trois d'un coup.
- `DossierEtapesRestantes` : état `done`/`current` porté uniquement par une icône
  `aria-hidden` et par la couleur — même défaut que la checklist, corrigé ici.
- Textes français en dur restants : `DossierEtapesRestantes`, `dossier/page.tsx`,
  message 409 de `dossier/photo/actions.ts`.
- Aucun test Playwright ne couvre `submitted-photo-reminder` ni
  `photo-late-upload-notice` : les deux exigent un backend live et un dossier
  soumis sans photo.
- `pssfp-frontend` : **545 redémarrages** pm2. Sans rapport avec ce lot.
