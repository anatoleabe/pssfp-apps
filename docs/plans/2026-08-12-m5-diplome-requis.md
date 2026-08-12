# Plan d'implémentation — Diplôme requis & autres diplômes (module 5)

> **Pour agents d'exécution :** SOUS-SKILL REQUISE — utiliser `superpowers:subagent-driven-development` (recommandé) ou `superpowers:executing-plans` pour exécuter ce plan tâche par tâche. Les étapes utilisent la syntaxe case à cocher (`- [ ]`).

**Objectif :** Ajouter au formulaire de candidature un bloc « diplôme requis » distinct du diplôme le plus élevé, deux blocs répétables facultatifs « autres diplômes et formations », et remonter « Lieu de naissance » à l'étape 1 — sans invalider ni bloquer une seule candidature déjà en production.

**Architecture :** Sept colonnes additives et nullables sur `candidatures`, plus une colonne `form_version` qui vaut `1` sur tout l'existant et `2` par défaut sur les nouvelles lignes. La validation stricte à la soumission ne s'active que pour `form_version >= 2` ; pour `1` elle reste bit à bit celle d'aujourd'hui. Les deux blocs répétables sont stockés en JSONB, normalisés côté serveur avant écriture.

**Stack :** Laravel 11 / PHP 8.3 / PostgreSQL 16 / Pest côté backend · Next.js 14 App Router / TypeScript strict / zod 4 / next-intl / Playwright côté candidature.

**Spec de référence :** `docs/specs/module-5-evolution-diplomes-2026-08.md`

**Branche :** `feat/m5-diplome-requis` (déjà créée, spec déjà commitée dessus).

## Contraintes globales

- Site **en production**. Aucune candidature existante ne doit être invalidée, bloquée ou modifiée. Toute nouvelle colonne est nullable ; toute nouvelle exigence est conditionnée à `form_version >= 2`.
- Migrations **toujours réversibles** : `down()` complet et testé.
- **Aucun texte en dur dans le JSX** — tout passe par `next-intl` (`t('clé')`), `messages/fr.json` **et** `messages/en.json`.
- **Immutabilité** : jamais de mutation en place d'un tableau ou d'un objet d'état. Toujours produire une nouvelle valeur.
- **Fichiers < 800 lignes.** `candidature/components/DossierEditionForm/index.tsx` fait 887 lignes et doit repasser sous la limite (tâche 9).
- **Commits Conventional Commits**, un par tâche minimum.
- Valeurs stockées en base pour les listes déroulantes : **slugs stables** (`licence-bachelor`, `master`, `droit`, `economie`, `gestion`, `autres`), jamais les libellés affichés.
- Plafond de **10 lignes** par bloc répétable, appliqué côté API *et* côté UI.
- WCAG 2.1 AA : `fieldset`/`legend` sur les blocs répétables, un `label` par cellule, boutons de suppression nommés sans ambiguïté.

## Structure de fichiers

**Backend — créés**

| Fichier | Responsabilité |
|---|---|
| `backend/database/migrations/2026_08_12_090000_add_diplome_requis_and_autres_diplomes_to_candidatures.php` | 7 colonnes + `form_version` + CHECK |
| `backend/config/diplome_requis.php` | slug → libellé FR du diplôme requis |
| `backend/config/domaines_diplome.php` | slug → libellé FR du domaine |
| `backend/app/Support/CandidatureDiplomeBlocks.php` | normalisation et contrôle de complétude des lignes JSONB |
| `backend/tests/Unit/CandidatureDiplomeBlocksTest.php` | tests unitaires du normaliseur |
| `backend/tests/Feature/Applications/DiplomeRequisTest.php` | non-régression v1 / exigences v2 |

**Backend — modifiés**

| Fichier | Changement |
|---|---|
| `backend/app/Models/Candidature.php` | `$fillable` + `$casts` |
| `backend/app/Http/Requests/Applications/UpdateCandidatureRequest.php` | règles laxistes des nouveaux champs |
| `backend/app/Services/CandidatureService.php` | `form_version` en liste noire, normalisation JSONB, `checkSubmittable` conditionnel |
| `backend/app/Http/Resources/CandidatureResource.php` | exposition des nouveaux champs + `form_version` |
| `backend/app/Filament/Resources/CandidatureResource.php` | champs admin + 2 `Repeater` |
| `backend/resources/views/pdf/candidature-recipisse.blade.php` | rendu conditionnel |

**Candidature — créés**

| Fichier | Responsabilité |
|---|---|
| `candidature/lib/diplomes/rows.ts` | types et constantes des lignes répétables |
| `candidature/components/RepeatableRows/index.tsx` | composant générique de lignes répétables |
| `candidature/components/diplomes/AutresDiplomesEtFormations.tsx` | les deux blocs, partagé wizard ↔ édition |
| `candidature/components/DossierEditionForm/primitives.tsx` | `Card`, `Field`, `inputCls`, `SectionPropsBase` extraits |
| `candidature/components/DossierEditionForm/SectionDiplome.tsx` | section diplôme extraite et étendue |
| `candidature/tests/playwright/diplome-requis.spec.ts` | E2E des nouveaux champs |

**Candidature — modifiés**

| Fichier | Changement |
|---|---|
| `candidature/lib/api/types.ts` | `CandidatureProfile` + nouveaux champs |
| `candidature/lib/api/client.ts` | `MyCandidature.form_version` |
| `candidature/lib/dossier/options.ts` | listes d'options + prédicat conditionnel |
| `candidature/lib/dossier/editableFields.ts` | nouveaux champs éditables, type de valeur élargi |
| `candidature/lib/validation/schemas.ts` | `lieu_naissance` étape 1, nouveaux champs étape 3 |
| `candidature/lib/validation/submittable.ts` | miroir de `checkSubmittable` |
| `candidature/components/wizard/types.ts` | `WizardData`, `initialWizardData`, `WizardErrors` |
| `candidature/components/wizard/WizardContainer.tsx` | clés d'erreur en chemin complet, listes d'étapes |
| `candidature/components/wizard/WizardStep1Identite.tsx` | accueille `lieu_naissance` |
| `candidature/components/wizard/WizardStep2Coordonnees.tsx` | perd `lieu_naissance` |
| `candidature/components/wizard/WizardStep3Diplome.tsx` | deux blocs + blocs répétables |
| `candidature/components/wizard/WizardStep5Review.tsx` | récapitulatif étendu |
| `candidature/components/DossierEditionForm/index.tsx` | extraction + état des tableaux |
| `candidature/components/dossier/DossierCompleteness.tsx` | libellés des nouveaux champs |
| `candidature/components/dossier/DossierProfileSummary.tsx` | affichage des nouveaux champs |
| `candidature/app/[locale]/inscription/actions.ts` | envoi des nouveaux champs |
| `candidature/messages/fr.json`, `candidature/messages/en.json` | libellés |
| `candidature/tests/playwright/inscription-wizard.spec.ts` | `lieu_naissance` passe à l'étape 1 |

---

### Tâche 1 : Socle données — migration, config, normaliseur

**Fichiers :**
- Créer : `backend/database/migrations/2026_08_12_090000_add_diplome_requis_and_autres_diplomes_to_candidatures.php`
- Créer : `backend/config/diplome_requis.php`
- Créer : `backend/config/domaines_diplome.php`
- Créer : `backend/app/Support/CandidatureDiplomeBlocks.php`
- Créer : `backend/tests/Unit/CandidatureDiplomeBlocksTest.php`
- Créer : `backend/tests/Feature/Applications/DiplomeRequisTest.php`
- Modifier : `backend/app/Models/Candidature.php`

**Interfaces :**
- Produit : `CandidatureDiplomeBlocks::normalize(mixed $rows, array $keys): array`, `CandidatureDiplomeBlocks::isRowComplete(array $row, array $keys): bool`, constantes `MAX_ROWS`, `AUTRE_DIPLOME_KEYS`, `FORMATION_PRO_KEYS`.
- Produit : colonnes `diplome_requis`, `annee_diplome_requis`, `domaine_diplome_requis`, `specialite_diplome_requis`, `institut_diplome_requis`, `autres_diplomes`, `formations_professionnelles`, `form_version`.
- Consommé par : tâches 2, 3, 4.

- [ ] **Étape 1.1 : Écrire le test unitaire du normaliseur (il doit échouer)**

Créer `backend/tests/Unit/CandidatureDiplomeBlocksTest.php` :

```php
<?php

declare(strict_types=1);

use App\Support\CandidatureDiplomeBlocks;

uses()->group('candidatures', 'diplome-requis');

it('ne garde que les clés attendues', function (): void {
    $rows = [
        ['intitule' => 'DESS', 'etablissement' => 'ENAM', 'annee' => 2019, 'statut' => 'admin'],
    ];

    expect(CandidatureDiplomeBlocks::normalize($rows, CandidatureDiplomeBlocks::AUTRE_DIPLOME_KEYS))
        ->toBe([['intitule' => 'DESS', 'etablissement' => 'ENAM', 'annee' => 2019]]);
});

it('coupe les espaces et convertit les chaînes vides en null', function (): void {
    $rows = [['intitule' => '  DESS  ', 'etablissement' => '   ', 'annee' => '2019']];

    expect(CandidatureDiplomeBlocks::normalize($rows, CandidatureDiplomeBlocks::AUTRE_DIPLOME_KEYS))
        ->toBe([['intitule' => 'DESS', 'etablissement' => null, 'annee' => 2019]]);
});

it('supprime les lignes entièrement vides', function (): void {
    $rows = [
        ['intitule' => '', 'etablissement' => '', 'annee' => ''],
        ['intitule' => 'DESS', 'etablissement' => 'ENAM', 'annee' => 2019],
    ];

    expect(CandidatureDiplomeBlocks::normalize($rows, CandidatureDiplomeBlocks::AUTRE_DIPLOME_KEYS))
        ->toHaveCount(1);
});

it('plafonne à dix lignes', function (): void {
    $rows = array_fill(0, 15, ['intitule' => 'DESS', 'etablissement' => 'ENAM', 'annee' => 2019]);

    expect(CandidatureDiplomeBlocks::normalize($rows, CandidatureDiplomeBlocks::AUTRE_DIPLOME_KEYS))
        ->toHaveCount(CandidatureDiplomeBlocks::MAX_ROWS);
});

it('renvoie un tableau vide pour une valeur non tableau', function (): void {
    expect(CandidatureDiplomeBlocks::normalize('nope', CandidatureDiplomeBlocks::AUTRE_DIPLOME_KEYS))->toBe([])
        ->and(CandidatureDiplomeBlocks::normalize(null, CandidatureDiplomeBlocks::AUTRE_DIPLOME_KEYS))->toBe([]);
});

it('rejette une année non numérique', function (): void {
    $rows = [['intitule' => 'DESS', 'etablissement' => 'ENAM', 'annee' => 'mille']];

    expect(CandidatureDiplomeBlocks::normalize($rows, CandidatureDiplomeBlocks::AUTRE_DIPLOME_KEYS))
        ->toBe([['intitule' => 'DESS', 'etablissement' => 'ENAM', 'annee' => null]]);
});

it('normalise aussi les formations professionnelles', function (): void {
    $rows = [['centre' => 'ISMP', 'qualification' => 'Certificat', 'annee' => 2021, 'x' => 1]];

    expect(CandidatureDiplomeBlocks::normalize($rows, CandidatureDiplomeBlocks::FORMATION_PRO_KEYS))
        ->toBe([['centre' => 'ISMP', 'qualification' => 'Certificat', 'annee' => 2021]]);
});

it('détecte une ligne incomplète', function (): void {
    $complete = ['intitule' => 'DESS', 'etablissement' => 'ENAM', 'annee' => 2019];
    $incomplete = ['intitule' => 'DESS', 'etablissement' => null, 'annee' => 2019];

    expect(CandidatureDiplomeBlocks::isRowComplete($complete, CandidatureDiplomeBlocks::AUTRE_DIPLOME_KEYS))->toBeTrue()
        ->and(CandidatureDiplomeBlocks::isRowComplete($incomplete, CandidatureDiplomeBlocks::AUTRE_DIPLOME_KEYS))->toBeFalse();
});
```

- [ ] **Étape 1.2 : Lancer le test pour vérifier qu'il échoue**

```bash
cd backend && php artisan test tests/Unit/CandidatureDiplomeBlocksTest.php
```

Attendu : ÉCHEC — `Class "App\Support\CandidatureDiplomeBlocks" not found`.

- [ ] **Étape 1.3 : Écrire le normaliseur**

Créer `backend/app/Support/CandidatureDiplomeBlocks.php` :

```php
<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Normalisation des blocs répétables « autres diplômes » et « formations
 * professionnelles », stockés en JSONB sur `candidatures`.
 *
 * Le client envoie un tableau d'objets. On ne fait jamais confiance à sa forme :
 * seules les clés attendues survivent, les valeurs sont typées, les lignes vides
 * disparaissent et le nombre de lignes est plafonné. Aucun JSON arbitraire
 * n'atteint la base.
 */
final class CandidatureDiplomeBlocks
{
    public const MAX_ROWS = 10;

    /** @var list<string> */
    public const AUTRE_DIPLOME_KEYS = ['intitule', 'etablissement', 'annee'];

    /** @var list<string> */
    public const FORMATION_PRO_KEYS = ['centre', 'qualification', 'annee'];

    /**
     * @param  list<string>  $keys
     * @return list<array<string, string|int|null>>
     */
    public static function normalize(mixed $rows, array $keys): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $normalized = [];

        foreach (array_slice(array_values($rows), 0, self::MAX_ROWS) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $clean = [];
            foreach ($keys as $key) {
                $clean[$key] = $key === 'annee'
                    ? self::normalizeYear($row[$key] ?? null)
                    : self::normalizeText($row[$key] ?? null);
            }

            $hasValue = array_filter($clean, static fn ($value): bool => $value !== null) !== [];
            if ($hasValue) {
                $normalized[] = $clean;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, string|int|null>  $row
     * @param  list<string>  $keys
     */
    public static function isRowComplete(array $row, array $keys): bool
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;
            if ($value === null || $value === '') {
                return false;
            }
        }

        return true;
    }

    private static function normalizeText(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function normalizeYear(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit(trim($value)) && trim($value) !== '') {
            return (int) trim($value);
        }

        return null;
    }
}
```

- [ ] **Étape 1.4 : Relancer le test unitaire**

```bash
cd backend && php artisan test tests/Unit/CandidatureDiplomeBlocksTest.php
```

Attendu : SUCCÈS, 8 tests passés.

- [ ] **Étape 1.5 : Écrire le test de migration (il doit échouer)**

Créer `backend/tests/Feature/Applications/DiplomeRequisTest.php` :

```php
<?php

declare(strict_types=1);

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Illuminate\Support\Facades\DB;

uses()->group('applications', 'diplome-requis');

beforeEach(function (): void {
    $this->seed([
        PaysSeeder::class,
        RegionsCamerounSeeder::class,
        DepartementsCamerounSeeder::class,
    ]);

    $this->campagne = CampagneCandidature::factory()->create([
        'status' => 'open',
        'opens_at' => now()->subMonth(),
        'closes_at' => now()->addMonth(),
    ]);
});

it('crée les nouvelles lignes avec form_version 2', function (): void {
    $candidature = Candidature::factory()->create(['campagne_id' => $this->campagne->id]);

    expect($candidature->refresh()->form_version)->toBe(2);
});

it('accepte les nouveaux champs et les blocs JSONB', function (): void {
    $candidature = Candidature::factory()->create([
        'campagne_id' => $this->campagne->id,
        'diplome_requis' => 'licence-bachelor',
        'annee_diplome_requis' => 2018,
        'domaine_diplome_requis' => 'droit',
        'institut_diplome_requis' => 'Université de Yaoundé II',
        'autres_diplomes' => [
            ['intitule' => 'DESS', 'etablissement' => 'ENAM', 'annee' => 2019],
        ],
        'formations_professionnelles' => [
            ['centre' => 'ISMP', 'qualification' => 'Certificat', 'annee' => 2021],
        ],
    ]);

    $fresh = $candidature->refresh();

    expect($fresh->diplome_requis)->toBe('licence-bachelor')
        ->and($fresh->annee_diplome_requis)->toBe(2018)
        ->and($fresh->autres_diplomes)->toBe([
            ['intitule' => 'DESS', 'etablissement' => 'ENAM', 'annee' => 2019],
        ])
        ->and($fresh->formations_professionnelles[0]['centre'])->toBe('ISMP');
});

it('refuse un slug de diplôme requis hors liste', function (): void {
    expect(fn () => Candidature::factory()->create([
        'campagne_id' => $this->campagne->id,
        'diplome_requis' => 'doctorat',
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

it('refuse un slug de domaine hors liste', function (): void {
    expect(fn () => Candidature::factory()->create([
        'campagne_id' => $this->campagne->id,
        'domaine_diplome_requis' => 'informatique',
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

it('tolère les nouvelles colonnes à null', function (): void {
    $candidature = Candidature::factory()->create(['campagne_id' => $this->campagne->id]);

    expect($candidature->diplome_requis)->toBeNull()
        ->and($candidature->autres_diplomes)->toBeNull();
});

it('expose les libellés de référence en configuration', function (): void {
    expect(array_keys((array) config('diplome_requis')))->toBe(['licence-bachelor', 'master'])
        ->and(array_keys((array) config('domaines_diplome')))->toBe(['droit', 'economie', 'gestion', 'autres']);
});

it('applique le défaut 2 au niveau du schéma, pas du modèle', function (): void {
    $id = DB::table('candidatures')->insertGetId([
        'uuid' => (string) Illuminate\Support\Str::uuid(),
        'numero_dossier' => 'TEST-'.uniqid(),
        'campagne_id' => $this->campagne->id,
        'phone_e164' => '+237691000111',
        'phone_country' => 'CM',
        'nom' => 'Test',
        'prenom' => 'Direct',
        'date_naissance' => '1990-01-01',
        'statut' => 'postulant',
        'frais_paye' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('candidatures')->where('id', $id)->value('form_version'))->toBe(2);
});
```

- [ ] **Étape 1.6 : Lancer le test pour vérifier qu'il échoue**

```bash
cd backend && php artisan test tests/Feature/Applications/DiplomeRequisTest.php
```

Attendu : ÉCHEC — colonne `form_version` inexistante.

- [ ] **Étape 1.7 : Écrire les deux fichiers de configuration**

Créer `backend/config/diplome_requis.php` :

```php
<?php

declare(strict_types=1);

/*
 * Diplôme requis pour l'admission (`candidatures.diplome_requis`).
 *
 * La clé est le slug stocké en base — il ne change jamais. La valeur est le
 * libellé français, utilisé pour le rendu du récépissé PDF et l'admin Filament.
 * Le frontend a sa propre liste dans candidature/lib/dossier/options.ts : les
 * deux doivent rester alignées sur les mêmes slugs.
 */
return [
    'licence-bachelor' => 'Licence / Bachelor',
    'master' => 'Master',
];
```

Créer `backend/config/domaines_diplome.php` :

```php
<?php

declare(strict_types=1);

/*
 * Domaine du diplôme requis (`candidatures.domaine_diplome_requis`).
 *
 * Le domaine `autres` déclenche l'obligation de renseigner
 * `specialite_diplome_requis` au moment de la soumission.
 */
return [
    'droit' => 'Droit',
    'economie' => 'Économie',
    'gestion' => 'Gestion',
    'autres' => 'Autres',
];
```

- [ ] **Étape 1.8 : Écrire la migration**

Créer `backend/database/migrations/2026_08_12_090000_add_diplome_requis_and_autres_diplomes_to_candidatures.php` :

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Évolution « diplôme requis & autres diplômes » (cf. docs/specs/module-5-evolution-diplomes-2026-08.md).
 *
 * Migration strictement additive : toutes les nouvelles colonnes métier sont
 * NULLABLE, aucune ligne de production n'est réécrite ni invalidée.
 *
 * `form_version` discrimine l'ancien formulaire du nouveau :
 *   1. ADD COLUMN ... DEFAULT 1  -> toutes les lignes existantes prennent 1
 *   2. ALTER COLUMN ... SET DEFAULT 2 -> toute ligne insérée ensuite prend 2
 * Aucun code applicatif ne fixe cette colonne : le défaut Postgres suffit, et
 * CandidatureService la maintient en liste noire du PUT.
 *
 * Les contraintes CHECK tolèrent NULL (sémantique Postgres) : elles ne peuvent
 * donc pas rejeter une ligne préexistante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidatures', function (Blueprint $table): void {
            $table->string('diplome_requis', 30)->nullable()->after('annee_diplome');
            $table->smallInteger('annee_diplome_requis')->nullable()->after('diplome_requis');
            $table->string('domaine_diplome_requis', 30)->nullable()->after('annee_diplome_requis');
            $table->string('specialite_diplome_requis', 100)->nullable()->after('domaine_diplome_requis');
            $table->string('institut_diplome_requis', 150)->nullable()->after('specialite_diplome_requis');
            $table->jsonb('autres_diplomes')->nullable()->after('institut_diplome_requis');
            $table->jsonb('formations_professionnelles')->nullable()->after('autres_diplomes');
            $table->smallInteger('form_version')->default(1)->after('statut');
        });

        DB::statement('ALTER TABLE candidatures ALTER COLUMN form_version SET DEFAULT 2');
        DB::statement('ALTER TABLE candidatures ALTER COLUMN form_version SET NOT NULL');

        DB::statement(<<<'SQL'
            ALTER TABLE candidatures
            ADD CONSTRAINT candidatures_diplome_requis_check
            CHECK (diplome_requis IN ('licence-bachelor', 'master'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE candidatures
            ADD CONSTRAINT candidatures_domaine_diplome_requis_check
            CHECK (domaine_diplome_requis IN ('droit', 'economie', 'gestion', 'autres'))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE candidatures DROP CONSTRAINT IF EXISTS candidatures_diplome_requis_check');
        DB::statement('ALTER TABLE candidatures DROP CONSTRAINT IF EXISTS candidatures_domaine_diplome_requis_check');

        Schema::table('candidatures', function (Blueprint $table): void {
            $table->dropColumn([
                'diplome_requis',
                'annee_diplome_requis',
                'domaine_diplome_requis',
                'specialite_diplome_requis',
                'institut_diplome_requis',
                'autres_diplomes',
                'formations_professionnelles',
                'form_version',
            ]);
        });
    }
};
```

- [ ] **Étape 1.9 : Étendre le modèle**

Dans `backend/app/Models/Candidature.php`, ajouter au tableau `$fillable`, juste après `'annee_diplome',` :

```php
        'diplome_requis',
        'annee_diplome_requis',
        'domaine_diplome_requis',
        'specialite_diplome_requis',
        'institut_diplome_requis',
        'autres_diplomes',
        'formations_professionnelles',
```

`form_version` n'est **volontairement pas** dans `$fillable` : c'est un champ système, fixé par le défaut Postgres.

Ajouter au tableau `$casts` :

```php
        'annee_diplome_requis' => 'integer',
        'autres_diplomes' => 'array',
        'formations_professionnelles' => 'array',
        'form_version' => 'integer',
```

- [ ] **Étape 1.10 : Lancer les tests**

```bash
cd backend && php artisan test tests/Feature/Applications/DiplomeRequisTest.php tests/Unit/CandidatureDiplomeBlocksTest.php
```

Attendu : SUCCÈS sur les 15 tests.

- [ ] **Étape 1.11 : Vérifier la réversibilité de la migration**

```bash
cd backend && php artisan migrate --step && php artisan migrate:rollback --step=1 && php artisan migrate
```

Attendu : les trois commandes se terminent sans erreur. Le rollback supprime les huit colonnes et les deux contraintes.

- [ ] **Étape 1.12 : Commit**

```bash
git add backend/database/migrations/2026_08_12_090000_add_diplome_requis_and_autres_diplomes_to_candidatures.php \
        backend/config/diplome_requis.php backend/config/domaines_diplome.php \
        backend/app/Support/CandidatureDiplomeBlocks.php backend/app/Models/Candidature.php \
        backend/tests/Unit/CandidatureDiplomeBlocksTest.php \
        backend/tests/Feature/Applications/DiplomeRequisTest.php
git commit -m "feat(candidatures): colonnes diplôme requis, blocs répétables et form_version"
```

---

### Tâche 2 : API — validation laxiste, normalisation, exposition

**Fichiers :**
- Modifier : `backend/app/Http/Requests/Applications/UpdateCandidatureRequest.php`
- Modifier : `backend/app/Services/CandidatureService.php` (méthode `updateDraft`)
- Modifier : `backend/app/Http/Resources/CandidatureResource.php`
- Modifier : `backend/tests/Feature/Applications/DiplomeRequisTest.php`

**Interfaces :**
- Consomme : `CandidatureDiplomeBlocks` (tâche 1), colonnes de la tâche 1.
- Produit : la réponse `GET /v1/applications/me` contient désormais `diplome_requis`, `annee_diplome_requis`, `domaine_diplome_requis`, `specialite_diplome_requis`, `institut_diplome_requis`, `autres_diplomes` (tableau, jamais `null`), `formations_professionnelles` (tableau, jamais `null`), `form_version` (entier). Le frontend s'appuie sur ce contrat aux tâches 5 et 9.

- [ ] **Étape 2.1 : Écrire les tests d'API (ils doivent échouer)**

Ajouter `use App\Models\User;` **en tête** de `backend/tests/Feature/Applications/DiplomeRequisTest.php`, à côté des autres `use` — jamais en fin de fichier. Puis ajouter à la fin du fichier :

```php
function authedCandidatDiplome(): array
{
    $user = User::factory()->candidat()->create([
        'phone_e164' => '+237691'.fake()->numerify('######'),
        'phone_country' => 'CM',
        'date_naissance' => '1990-06-15',
    ]);
    $token = $user->createToken('candidat', [
        'profile:read', 'profile:write', 'application:create',
        'application:read', 'application:submit',
    ])->plainTextToken;

    return [$user, $token];
}

it('enregistre les nouveaux champs via PUT /v1/applications/me', function (): void {
    [, $token] = authedCandidatDiplome();

    $response = $this->withToken($token)->putJson('/v1/applications/me', [
        'diplome_requis' => 'master',
        'annee_diplome_requis' => 2020,
        'domaine_diplome_requis' => 'gestion',
        'institut_diplome_requis' => 'Université de Douala',
        'autres_diplomes' => [
            ['intitule' => 'DESS', 'etablissement' => 'ENAM', 'annee' => 2019],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.diplome_requis', 'master')
        ->assertJsonPath('data.annee_diplome_requis', 2020)
        ->assertJsonPath('data.domaine_diplome_requis', 'gestion')
        ->assertJsonPath('data.institut_diplome_requis', 'Université de Douala')
        ->assertJsonPath('data.autres_diplomes.0.intitule', 'DESS')
        ->assertJsonPath('data.form_version', 2);
});

it('renvoie un tableau vide et non null pour les blocs jamais renseignés', function (): void {
    [, $token] = authedCandidatDiplome();

    $this->withToken($token)->putJson('/v1/applications/me', ['nom' => 'Ndongo'])->assertOk();

    $this->withToken($token)->getJson('/v1/applications/me')
        ->assertOk()
        ->assertJsonPath('data.autres_diplomes', [])
        ->assertJsonPath('data.formations_professionnelles', []);
});

it('rejette un slug de diplôme requis inconnu', function (): void {
    [, $token] = authedCandidatDiplome();

    $this->withToken($token)->putJson('/v1/applications/me', ['diplome_requis' => 'doctorat'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('diplome_requis');
});

it('tolère une ligne incomplète au PUT pour ne pas casser l’auto-save', function (): void {
    [, $token] = authedCandidatDiplome();

    $this->withToken($token)->putJson('/v1/applications/me', [
        'autres_diplomes' => [['intitule' => 'DE', 'etablissement' => '', 'annee' => null]],
    ])->assertOk();
});

it('écarte les clés inconnues envoyées dans une ligne', function (): void {
    [, $token] = authedCandidatDiplome();

    $this->withToken($token)->putJson('/v1/applications/me', [
        'formations_professionnelles' => [
            ['centre' => 'ISMP', 'qualification' => 'Certificat', 'annee' => 2021, 'statut' => 'accepte'],
        ],
    ])->assertOk()
        ->assertJsonPath('data.formations_professionnelles.0.centre', 'ISMP')
        ->assertJsonMissingPath('data.formations_professionnelles.0.statut');
});

it('refuse plus de dix lignes', function (): void {
    [, $token] = authedCandidatDiplome();

    $rows = array_fill(0, 11, ['intitule' => 'DESS', 'etablissement' => 'ENAM', 'annee' => 2019]);

    $this->withToken($token)->putJson('/v1/applications/me', ['autres_diplomes' => $rows])
        ->assertStatus(422)
        ->assertJsonValidationErrors('autres_diplomes');
});

it('interdit de forcer form_version via le body', function (): void {
    [, $token] = authedCandidatDiplome();

    $this->withToken($token)->putJson('/v1/applications/me', [
        'nom' => 'Ndongo',
        'form_version' => 1,
    ])->assertOk()
        ->assertJsonPath('data.form_version', 2);
});
```

- [ ] **Étape 2.2 : Lancer les tests pour vérifier qu'ils échouent**

```bash
cd backend && php artisan test tests/Feature/Applications/DiplomeRequisTest.php
```

Attendu : ÉCHEC sur les sept nouveaux tests — les champs ne sont ni validés ni exposés.

- [ ] **Étape 2.3 : Étendre les règles de validation**

Dans `backend/app/Http/Requests/Applications/UpdateCandidatureRequest.php`, insérer dans le tableau retourné par `rules()`, juste après la ligne `'annee_diplome' => [...],` :

```php
            // Diplôme requis pour l'admission — distinct du diplôme le plus
            // élevé obtenu. Validation laxiste ici : l'obligation est portée
            // par CandidatureService::checkSubmittable, et seulement pour
            // form_version >= 2.
            'diplome_requis' => ['sometimes', 'nullable', 'string', Rule::in(array_keys((array) config('diplome_requis', [])))],
            'annee_diplome_requis' => ['sometimes', 'nullable', 'integer', 'min:1950', 'max:'.now()->year],
            'domaine_diplome_requis' => ['sometimes', 'nullable', 'string', Rule::in(array_keys((array) config('domaines_diplome', [])))],
            'specialite_diplome_requis' => ['sometimes', 'nullable', 'string', 'max:100'],
            'institut_diplome_requis' => ['sometimes', 'nullable', 'string', 'max:150'],

            // Blocs répétables. Les champs de ligne restent nullable : une
            // ligne à moitié saisie ne doit pas faire échouer l'auto-save 2 s.
            // Leur complétude est vérifiée à la soumission.
            'autres_diplomes' => ['sometimes', 'nullable', 'array', 'max:10'],
            'autres_diplomes.*' => ['array'],
            'autres_diplomes.*.intitule' => ['nullable', 'string', 'max:150'],
            'autres_diplomes.*.etablissement' => ['nullable', 'string', 'max:150'],
            'autres_diplomes.*.annee' => ['nullable', 'integer', 'min:1950', 'max:'.now()->year],
            'formations_professionnelles' => ['sometimes', 'nullable', 'array', 'max:10'],
            'formations_professionnelles.*' => ['array'],
            'formations_professionnelles.*.centre' => ['nullable', 'string', 'max:150'],
            'formations_professionnelles.*.qualification' => ['nullable', 'string', 'max:150'],
            'formations_professionnelles.*.annee' => ['nullable', 'integer', 'min:1950', 'max:'.now()->year],
```

- [ ] **Étape 2.4 : Normaliser les blocs et verrouiller `form_version`**

Dans `backend/app/Services/CandidatureService.php`, ajouter l'import en tête de fichier :

```php
use App\Support\CandidatureDiplomeBlocks;
```

Puis, dans `updateDraft`, remplacer tout ce qui se trouve entre le contrôle de statut et le `return $candidature->refresh();` final par :

```php
        // Empêche la modification de champs systèmes via le body PUT.
        $forbidden = ['id', 'uuid', 'numero_dossier', 'campagne_id', 'user_id',
            'statut', 'form_version', 'submitted_at', 'reviewed_at', 'decided_at', 'withdrawn_at',
            'frais_paye', 'mode_paiement', 'reference_paiement', 'date_paiement',
            'recipisse_pdf_path', 'recipisse_hash_sha256',
            'created_at', 'updated_at', 'deleted_at',
        ];
        $clean = array_diff_key($fields, array_flip($forbidden));

        // Les blocs répétables ne sont jamais stockés tels quels : seules les
        // clés attendues survivent (cf. CandidatureDiplomeBlocks).
        if (array_key_exists('autres_diplomes', $clean)) {
            $clean['autres_diplomes'] = CandidatureDiplomeBlocks::normalize(
                $clean['autres_diplomes'],
                CandidatureDiplomeBlocks::AUTRE_DIPLOME_KEYS,
            );
        }
        if (array_key_exists('formations_professionnelles', $clean)) {
            $clean['formations_professionnelles'] = CandidatureDiplomeBlocks::normalize(
                $clean['formations_professionnelles'],
                CandidatureDiplomeBlocks::FORMATION_PRO_KEYS,
            );
        }

        $candidature->fill($clean)->save();
```

- [ ] **Étape 2.5 : Exposer les nouveaux champs dans la ressource API**

Dans `backend/app/Http/Resources/CandidatureResource.php`, insérer juste après la ligne `'annee_diplome' => $this->annee_diplome,` :

```php
            'diplome_requis' => $this->diplome_requis,
            'annee_diplome_requis' => $this->annee_diplome_requis,
            'domaine_diplome_requis' => $this->domaine_diplome_requis,
            'specialite_diplome_requis' => $this->specialite_diplome_requis,
            'institut_diplome_requis' => $this->institut_diplome_requis,
            // Toujours un tableau, jamais null : le frontend itère dessus sans garde.
            'autres_diplomes' => $this->autres_diplomes ?? [],
            'formations_professionnelles' => $this->formations_professionnelles ?? [],
            // Pilote l'affichage conditionnel des nouveaux champs côté client.
            'form_version' => (int) $this->form_version,
```

- [ ] **Étape 2.6 : Lancer les tests**

```bash
cd backend && php artisan test tests/Feature/Applications/DiplomeRequisTest.php tests/Feature/Applications/PartialUpdateTest.php tests/Feature/Applications/MeUpdateTest.php
```

Attendu : SUCCÈS. Les tests existants de `PartialUpdateTest` et `MeUpdateTest` passent toujours — c'est le contrôle de non-régression de cette tâche.

- [ ] **Étape 2.7 : Commit**

```bash
git add backend/app/Http/Requests/Applications/UpdateCandidatureRequest.php \
        backend/app/Services/CandidatureService.php \
        backend/app/Http/Resources/CandidatureResource.php \
        backend/tests/Feature/Applications/DiplomeRequisTest.php
git commit -m "feat(api): accepter et exposer les champs diplôme requis et blocs répétables"
```

---

### Tâche 3 : Soumission — exigences conditionnées à `form_version`

**Fichiers :**
- Modifier : `backend/app/Services/CandidatureService.php` (méthode `checkSubmittable`)
- Modifier : `backend/tests/Feature/Applications/DiplomeRequisTest.php`

**Interfaces :**
- Consomme : `CandidatureDiplomeBlocks`, colonnes et `form_version` de la tâche 1.
- Produit : les clés d'erreur `diplome_requis`, `annee_diplome_requis`, `domaine_diplome_requis`, `institut_diplome_requis`, `specialite_diplome_requis`, `autres_diplomes.{i}`, `formations_professionnelles.{i}`. La tâche 9 en produit le miroir TypeScript.

- [ ] **Étape 3.1 : Écrire les tests de soumission (ils doivent échouer)**

Ajouter à la fin de `backend/tests/Feature/Applications/DiplomeRequisTest.php` (le `use Illuminate\Support\Facades\DB;` en tête du fichier, posé à l'étape 1.5, suffit — ne pas ajouter de `use` en fin de fichier) :

```php
/** Dossier complet au sens de l'ancien formulaire, prêt à être soumis. */
function candidatureComplete(int $campagneId, array $overrides = []): Candidature
{
    return Candidature::factory()->create(array_merge([
        'campagne_id' => $campagneId,
        'statut' => Candidature::STATUT_POSTULANT,
        'photo_path' => 'candidatures/test/photo.jpg',
        'civilite' => 'M.',
        'nom' => 'Ndongo',
        'prenom' => 'Paul',
        'date_naissance' => '1990-06-15',
        'lieu_naissance' => 'Yaoundé',
        'genre' => 'M',
        'statut_matrimonial' => 'Célibataire',
        'nationalite' => 'CM',
        'pays_origine' => 'CM',
        'pays_residence' => 'CM',
        'region' => 'CENTRE',
        'departement' => 'Mfoundi',
        'adresse' => 'BP 1234 Yaoundé',
        'ville_residence' => 'Yaoundé',
        'indicatif1' => '+237',
        'telephone1' => '691234567',
        'email' => 'paul.ndongo@example.com',
        'specialite' => array_values((array) config('specialites'))[0],
        'type_etude' => 'presentiel',
        'premiere_langue' => 'fr',
        'diplome_obtenu' => 'Master',
        'institut' => 'Université de Yaoundé II',
        'specialite_diplome' => 'Finances publiques',
        'annee_diplome' => 2015,
        'statut_actuel' => 'Etudiant',
        'moyen_connaissance' => 'Site officiel du PSSFP',
        'engagement_nom' => 'Paul Ndongo',
    ], $overrides));
}

function forceFormVersion(Candidature $candidature, int $version): Candidature
{
    DB::table('candidatures')->where('id', $candidature->id)->update(['form_version' => $version]);

    return $candidature->refresh();
}

it('laisse un dossier v1 soumissible sans les nouveaux champs', function (): void {
    $candidature = forceFormVersion(candidatureComplete($this->campagne->id), 1);

    expect(app(App\Services\CandidatureService::class)->checkSubmittable($candidature))->toBe([]);
});

it('bloque un dossier v2 tant que les nouveaux champs manquent', function (): void {
    $candidature = candidatureComplete($this->campagne->id);

    $errors = app(App\Services\CandidatureService::class)->checkSubmittable($candidature);

    expect($errors)->toHaveKeys([
        'diplome_requis', 'annee_diplome_requis', 'domaine_diplome_requis', 'institut_diplome_requis',
    ]);
});

it('laisse un dossier v2 complet soumissible', function (): void {
    $candidature = candidatureComplete($this->campagne->id, [
        'diplome_requis' => 'licence-bachelor',
        'annee_diplome_requis' => 2012,
        'domaine_diplome_requis' => 'droit',
        'institut_diplome_requis' => 'Université de Yaoundé II',
    ]);

    expect(app(App\Services\CandidatureService::class)->checkSubmittable($candidature))->toBe([]);
});

it('exige la spécialité du diplôme requis quand le domaine vaut autres', function (): void {
    $candidature = candidatureComplete($this->campagne->id, [
        'diplome_requis' => 'master',
        'annee_diplome_requis' => 2012,
        'domaine_diplome_requis' => 'autres',
        'institut_diplome_requis' => 'Université de Yaoundé II',
    ]);

    expect(app(App\Services\CandidatureService::class)->checkSubmittable($candidature))
        ->toHaveKey('specialite_diplome_requis');
});

it('n’exige pas la spécialité du diplôme requis hors domaine autres', function (): void {
    $candidature = candidatureComplete($this->campagne->id, [
        'diplome_requis' => 'master',
        'annee_diplome_requis' => 2012,
        'domaine_diplome_requis' => 'economie',
        'institut_diplome_requis' => 'Université de Yaoundé II',
    ]);

    expect(app(App\Services\CandidatureService::class)->checkSubmittable($candidature))
        ->not->toHaveKey('specialite_diplome_requis');
});

it('refuse une ligne de bloc répétable incomplète', function (): void {
    $candidature = candidatureComplete($this->campagne->id, [
        'diplome_requis' => 'master',
        'annee_diplome_requis' => 2012,
        'domaine_diplome_requis' => 'gestion',
        'institut_diplome_requis' => 'Université de Yaoundé II',
        'autres_diplomes' => [['intitule' => 'DESS', 'etablissement' => null, 'annee' => 2019]],
    ]);

    expect(app(App\Services\CandidatureService::class)->checkSubmittable($candidature))
        ->toHaveKey('autres_diplomes.0');
});

it('accepte des blocs répétables complets ou vides', function (): void {
    $service = app(App\Services\CandidatureService::class);

    $avecLignes = candidatureComplete($this->campagne->id, [
        'diplome_requis' => 'master',
        'annee_diplome_requis' => 2012,
        'domaine_diplome_requis' => 'gestion',
        'institut_diplome_requis' => 'Université de Yaoundé II',
        'autres_diplomes' => [['intitule' => 'DESS', 'etablissement' => 'ENAM', 'annee' => 2019]],
        'formations_professionnelles' => [],
    ]);

    expect($service->checkSubmittable($avecLignes))->toBe([]);
});

it('ignore les nouvelles exigences pour un dossier v1 même incomplet', function (): void {
    $candidature = forceFormVersion(candidatureComplete($this->campagne->id, [
        'autres_diplomes' => [['intitule' => 'DESS', 'etablissement' => null, 'annee' => null]],
    ]), 1);

    expect(app(App\Services\CandidatureService::class)->checkSubmittable($candidature))->toBe([]);
});
```

- [ ] **Étape 3.2 : Lancer les tests pour vérifier qu'ils échouent**

```bash
cd backend && php artisan test tests/Feature/Applications/DiplomeRequisTest.php
```

Attendu : ÉCHEC — `checkSubmittable` ne connaît pas encore les nouveaux champs, donc « bloque un dossier v2 » et les suivants échouent.

- [ ] **Étape 3.3 : Étendre `checkSubmittable`**

Dans `backend/app/Services/CandidatureService.php`, **remplacer** le `return $errors;` final de `checkSubmittable` par :

```php
        // Nouvelles exigences du formulaire 2026-08. Elles ne s'appliquent
        // qu'aux dossiers créés après la mise en production : un brouillon
        // antérieur (form_version = 1) reste soumissible avec l'ancien jeu de
        // champs, conformément à docs/specs/module-5-evolution-diplomes-2026-08.md.
        if ((int) ($candidature->form_version ?? 1) >= 2) {
            $errors = array_merge($errors, $this->checkDiplomeRequis($candidature));
        }

        return $errors;
```

Puis ajouter la méthode privée suivante, juste après `checkSubmittable` :

```php
    /**
     * Exigences propres au bloc « diplôme requis » et aux blocs répétables.
     *
     * @return array<string, string>
     */
    private function checkDiplomeRequis(Candidature $candidature): array
    {
        $errors = [];

        $required = [
            'diplome_requis' => 'Le diplôme requis est obligatoire.',
            'annee_diplome_requis' => "L'année d'obtention du diplôme requis est obligatoire.",
            'domaine_diplome_requis' => 'Le domaine du diplôme requis est obligatoire.',
            'institut_diplome_requis' => "L'établissement de délivrance du diplôme requis est obligatoire.",
        ];

        foreach ($required as $field => $message) {
            $value = $candidature->{$field};
            if ($value === null || $value === '') {
                $errors[$field] = $message;
            }
        }

        if ($candidature->domaine_diplome_requis === 'autres' && empty($candidature->specialite_diplome_requis)) {
            $errors['specialite_diplome_requis'] = 'La spécialité du diplôme requis est obligatoire lorsque le domaine est « Autres ».';
        }

        if ($candidature->annee_diplome_requis !== null && $candidature->annee_diplome_requis > now()->year) {
            $errors['annee_diplome_requis'] = "L'année d'obtention du diplôme requis ne peut pas être dans le futur.";
        }

        $blocks = [
            'autres_diplomes' => [
                'keys' => CandidatureDiplomeBlocks::AUTRE_DIPLOME_KEYS,
                'message' => 'Chaque diplôme complémentaire ajouté doit indiquer son intitulé, son établissement et son année.',
            ],
            'formations_professionnelles' => [
                'keys' => CandidatureDiplomeBlocks::FORMATION_PRO_KEYS,
                'message' => 'Chaque formation professionnelle ajoutée doit indiquer son centre, sa qualification et son année.',
            ],
        ];

        foreach ($blocks as $field => $spec) {
            $rows = CandidatureDiplomeBlocks::normalize($candidature->{$field}, $spec['keys']);
            foreach ($rows as $index => $row) {
                if (! CandidatureDiplomeBlocks::isRowComplete($row, $spec['keys'])) {
                    $errors["{$field}.{$index}"] = $spec['message'];
                }
            }
        }

        return $errors;
    }
```

- [ ] **Étape 3.4 : Lancer les tests**

```bash
cd backend && php artisan test tests/Feature/Applications/DiplomeRequisTest.php tests/Feature/Applications/SubmitTest.php
```

Attendu : SUCCÈS. `SubmitTest` passe toujours — c'est le contrôle de non-régression.

- [ ] **Étape 3.5 : Lancer toute la suite backend**

```bash
cd backend && php artisan test
```

Attendu : SUCCÈS complet, aucune régression.

- [ ] **Étape 3.6 : Commit**

```bash
git add backend/app/Services/CandidatureService.php backend/tests/Feature/Applications/DiplomeRequisTest.php
git commit -m "feat(candidatures): exiger le diplôme requis à la soumission pour form_version 2"
```

---

### Tâche 4 : Administration Filament et récépissé PDF

**Fichiers :**
- Modifier : `backend/app/Filament/Resources/CandidatureResource.php:156-159`
- Modifier : `backend/resources/views/pdf/candidature-recipisse.blade.php:479-483` et `:604-606`
- Modifier : `backend/tests/Feature/Applications/DiplomeRequisTest.php`

**Interfaces :**
- Consomme : colonnes de la tâche 1, configs `diplome_requis` et `domaines_diplome`.

- [ ] **Étape 4.1 : Écrire le test de rendu du récépissé (il doit échouer)**

Ajouter à la fin de `backend/tests/Feature/Applications/DiplomeRequisTest.php` :

```php
it('imprime les nouveaux champs sur le récépissé d’un dossier v2', function (): void {
    $candidature = candidatureComplete($this->campagne->id, [
        'diplome_requis' => 'licence-bachelor',
        'annee_diplome_requis' => 2012,
        'domaine_diplome_requis' => 'droit',
        'institut_diplome_requis' => 'Université de Yaoundé II',
        'autres_diplomes' => [['intitule' => 'DESS', 'etablissement' => 'ENAM', 'annee' => 2019]],
    ]);

    $html = view('pdf.candidature-recipisse', [
        'candidature' => $candidature,
        'campagne' => $this->campagne,
        'photoData' => null,
        'qrData' => null,
    ])->render();

    expect($html)->toContain('Licence / Bachelor')
        ->and($html)->toContain('Droit')
        ->and($html)->toContain('DESS')
        ->and($html)->toContain('ENAM');
});

it('n’ajoute rien au récépissé d’un dossier v1', function (): void {
    $candidature = forceFormVersion(candidatureComplete($this->campagne->id), 1);

    $html = view('pdf.candidature-recipisse', [
        'candidature' => $candidature,
        'campagne' => $this->campagne,
        'photoData' => null,
        'qrData' => null,
    ])->render();

    expect($html)->not->toContain('Diplôme requis')
        ->and($html)->not->toContain('Autres diplômes');
});
```

> Avant de lancer, ouvrir `backend/app/Services/RecipisseService.php` et relever les **noms exacts** des variables passées à la vue `pdf.candidature-recipisse`. Aligner le tableau ci-dessus sur ces noms — le test doit rendre la vue avec le même contrat que la production.

- [ ] **Étape 4.2 : Lancer le test pour vérifier qu'il échoue**

```bash
cd backend && php artisan test tests/Feature/Applications/DiplomeRequisTest.php --filter="récépissé"
```

Attendu : ÉCHEC — le HTML ne contient pas « Licence / Bachelor ».

- [ ] **Étape 4.3 : Étendre le récépissé**

Dans `backend/resources/views/pdf/candidature-recipisse.blade.php`, après le bloc de lignes existant qui affiche `specialite_diplome`, `annee_diplome` et `institut` (autour de la ligne 483), insérer :

```blade
  @if($candidature->diplome_requis)
    <tr>
      <td>{!! $field('Diplôme requis', config('diplome_requis.'.$candidature->diplome_requis, $candidature->diplome_requis)) !!}</td>
      <td>{!! $field("Année d'obtention du diplôme requis", $candidature->annee_diplome_requis) !!}</td>
    </tr>
    <tr>
      <td>{!! $field('Domaine du diplôme requis', config('domaines_diplome.'.$candidature->domaine_diplome_requis, $candidature->domaine_diplome_requis)) !!}</td>
      <td>{!! $field('Établissement du diplôme requis', $candidature->institut_diplome_requis) !!}</td>
    </tr>
    @if($candidature->specialite_diplome_requis)
      <tr>
        <td colspan="2">{!! $field('Spécialité du diplôme requis', $candidature->specialite_diplome_requis) !!}</td>
      </tr>
    @endif
  @endif

  @if(!empty($candidature->autres_diplomes) || !empty($candidature->formations_professionnelles))
    <tr>
      <td colspan="2">
        <span class="label">Autres diplômes et formations</span>
        <ul style="margin:2mm 0 0 4mm; padding:0;">
          @foreach(($candidature->autres_diplomes ?? []) as $ligne)
            <li>{{ $ligne['intitule'] }} — {{ $ligne['etablissement'] }} ({{ $ligne['annee'] }})</li>
          @endforeach
          @foreach(($candidature->formations_professionnelles ?? []) as $ligne)
            <li>{{ $ligne['qualification'] }} — {{ $ligne['centre'] }} ({{ $ligne['annee'] }})</li>
          @endforeach
        </ul>
      </td>
    </tr>
  @endif
```

> Le helper `$field(...)` et la classe CSS `label` existent déjà dans ce gabarit. Si la structure locale n'est pas un `<table>` à cet endroit, adapter le balisage aux lignes voisines plutôt que d'introduire un motif étranger au fichier.

- [ ] **Étape 4.4 : Étendre l'admin Filament**

Dans `backend/app/Filament/Resources/CandidatureResource.php`, après la ligne `Forms\Components\TextInput::make('annee_diplome')->numeric()->minValue(1950)->maxValue(now()->year),` insérer :

```php
                    Forms\Components\Select::make('diplome_requis')
                        ->label('Diplôme requis')
                        ->options(config('diplome_requis'))
                        ->native(false),
                    Forms\Components\TextInput::make('annee_diplome_requis')
                        ->label("Année d'obtention du diplôme requis")
                        ->numeric()->minValue(1950)->maxValue(now()->year),
                    Forms\Components\Select::make('domaine_diplome_requis')
                        ->label('Domaine du diplôme requis')
                        ->options(config('domaines_diplome'))
                        ->native(false)
                        ->live(),
                    Forms\Components\TextInput::make('specialite_diplome_requis')
                        ->label('Spécialité du diplôme requis')
                        ->maxLength(100)
                        ->visible(fn (Forms\Get $get): bool => $get('domaine_diplome_requis') === 'autres'),
                    Forms\Components\TextInput::make('institut_diplome_requis')
                        ->label('Établissement de délivrance du diplôme requis')
                        ->maxLength(150),
                    Forms\Components\Repeater::make('autres_diplomes')
                        ->label('Autres diplômes académiques')
                        ->schema([
                            Forms\Components\TextInput::make('intitule')->label('Intitulé du diplôme')->maxLength(150),
                            Forms\Components\TextInput::make('etablissement')->label("Établissement d'obtention")->maxLength(150),
                            Forms\Components\TextInput::make('annee')->label("Année d'obtention")->numeric()->minValue(1950)->maxValue(now()->year),
                        ])
                        ->columns(3)
                        ->maxItems(10)
                        ->defaultItems(0)
                        ->columnSpanFull(),
                    Forms\Components\Repeater::make('formations_professionnelles')
                        ->label('Formations professionnelles')
                        ->schema([
                            Forms\Components\TextInput::make('centre')->label('Centre de formation')->maxLength(150),
                            Forms\Components\TextInput::make('qualification')->label('Qualification obtenue')->maxLength(150),
                            Forms\Components\TextInput::make('annee')->label('Année de formation')->numeric()->minValue(1950)->maxValue(now()->year),
                        ])
                        ->columns(3)
                        ->maxItems(10)
                        ->defaultItems(0)
                        ->columnSpanFull(),
```

> Vérifier que `use Filament\Forms;` est déjà importé en tête du fichier — le reste de la Resource l'utilise déjà. Si `Forms\Get` n'est pas résolu, ajouter `use Filament\Forms\Get;` et utiliser `Get $get`.

- [ ] **Étape 4.5 : Lancer les tests**

```bash
cd backend && php artisan test tests/Feature/Applications/DiplomeRequisTest.php tests/Feature/Applications/RecipisseGenerationTest.php
```

Attendu : SUCCÈS, `RecipisseGenerationTest` inclus.

- [ ] **Étape 4.6 : Vérifier le rendu réel d'un récépissé**

```bash
cd backend && php artisan test tests/Feature/Applications/RecipisseGenerationTest.php -v
```

Attendu : SUCCÈS. Si le projet dispose d'une commande de prévisualisation PDF (`output/pdf/recipisse-pssfp-preview.pdf` en atteste), la lancer et ouvrir le PDF pour contrôler visuellement qu'aucune ligne vide n'apparaît sur un dossier v1.

- [ ] **Étape 4.7 : Commit**

```bash
git add backend/app/Filament/Resources/CandidatureResource.php \
        backend/resources/views/pdf/candidature-recipisse.blade.php \
        backend/tests/Feature/Applications/DiplomeRequisTest.php
git commit -m "feat(admin): afficher le diplôme requis et les blocs répétables en admin et sur le récépissé"
```

---

### Tâche 5 : Front — types, options, libellés

**Fichiers :**
- Modifier : `candidature/lib/api/types.ts`
- Modifier : `candidature/lib/api/client.ts`
- Modifier : `candidature/lib/dossier/options.ts`
- Créer : `candidature/lib/diplomes/rows.ts`
- Modifier : `candidature/messages/fr.json`
- Modifier : `candidature/messages/en.json`

**Interfaces :**
- Produit : `AutreDiplomeRow`, `FormationProRow`, `EMPTY_AUTRE_DIPLOME`, `EMPTY_FORMATION_PRO`, `MAX_DIPLOME_ROWS`, `AUTRE_DIPLOME_COLUMNS`, `FORMATION_PRO_COLUMNS` depuis `@/lib/diplomes/rows`.
- Produit : `DIPLOME_REQUIS_OPTIONS`, `DOMAINE_DIPLOME_OPTIONS`, `needsSpecialiteDiplomeRequis(domaine: string): boolean` depuis `@/lib/dossier/options`.
- Produit : les clés i18n `wizard.step1.lieuNaissance`, `wizard.step3.diplomeRequis*`, `wizard.step3.autresDiplomes*`, `dossier.fields.*` listées ci-dessous.
- Consommé par : tâches 6, 7, 8, 9.

- [ ] **Étape 5.1 : Créer les types de lignes**

Créer `candidature/lib/diplomes/rows.ts` :

```ts
/**
 * Lignes des blocs répétables « autres diplômes et formations ».
 *
 * `annee` accepte la chaîne vide pour représenter un champ numérique laissé
 * vide dans le DOM — la conversion en `number` a lieu à la saisie, jamais à
 * l'affichage.
 */
export interface AutreDiplomeRow {
  intitule: string;
  etablissement: string;
  annee: number | '';
}

export interface FormationProRow {
  centre: string;
  qualification: string;
  annee: number | '';
}

/** Aligné sur CandidatureDiplomeBlocks::MAX_ROWS côté backend. */
export const MAX_DIPLOME_ROWS = 10;

export const EMPTY_AUTRE_DIPLOME: AutreDiplomeRow = {
  intitule: '',
  etablissement: '',
  annee: '',
};

export const EMPTY_FORMATION_PRO: FormationProRow = {
  centre: '',
  qualification: '',
  annee: '',
};

export interface RepeatableColumn<T> {
  key: keyof T & string;
  labelKey: string;
  type: 'text' | 'year';
}

export const AUTRE_DIPLOME_COLUMNS: ReadonlyArray<RepeatableColumn<AutreDiplomeRow>> = [
  { key: 'intitule', labelKey: 'autresDiplomes.intitule', type: 'text' },
  { key: 'etablissement', labelKey: 'autresDiplomes.etablissement', type: 'text' },
  { key: 'annee', labelKey: 'autresDiplomes.annee', type: 'year' },
];

export const FORMATION_PRO_COLUMNS: ReadonlyArray<RepeatableColumn<FormationProRow>> = [
  { key: 'centre', labelKey: 'formationsPro.centre', type: 'text' },
  { key: 'qualification', labelKey: 'formationsPro.qualification', type: 'text' },
  { key: 'annee', labelKey: 'formationsPro.annee', type: 'year' },
];

/** Une ligne vide n'est pas envoyée au backend ni soumise à validation. */
export function isRowEmpty<T extends object>(row: T): boolean {
  return Object.values(row).every((value) => value === '' || value === null || value === undefined);
}
```

- [ ] **Étape 5.2 : Ajouter les options de listes déroulantes**

Ajouter à la fin de `candidature/lib/dossier/options.ts` :

```ts
/**
 * Diplôme requis pour l'admission. La valeur est le slug stocké en base — il
 * doit rester aligné sur backend/config/diplome_requis.php.
 */
export const DIPLOME_REQUIS_OPTIONS = [
  { value: 'licence-bachelor', label: 'Licence / Bachelor' },
  { value: 'master', label: 'Master' },
] as const;

/** Aligné sur backend/config/domaines_diplome.php. */
export const DOMAINE_DIPLOME_OPTIONS = [
  { value: 'droit', label: 'Droit' },
  { value: 'economie', label: 'Économie' },
  { value: 'gestion', label: 'Gestion' },
  { value: 'autres', label: 'Autres' },
] as const;

/** Seul le domaine « Autres » ouvre le champ de spécialité du diplôme requis. */
export function needsSpecialiteDiplomeRequis(domaine: string): boolean {
  return domaine === 'autres';
}
```

- [ ] **Étape 5.3 : Étendre les types API**

Dans `candidature/lib/api/types.ts`, ajouter à l'interface `CandidatureProfile`, après le champ `annee_diplome` :

```ts
  diplome_requis?: string | null;
  annee_diplome_requis?: number | null;
  domaine_diplome_requis?: string | null;
  specialite_diplome_requis?: string | null;
  institut_diplome_requis?: string | null;
  autres_diplomes?: AutreDiplomeRow[];
  formations_professionnelles?: FormationProRow[];
```

Et en tête du fichier :

```ts
import type { AutreDiplomeRow, FormationProRow } from '@/lib/diplomes/rows';
```

Dans `candidature/lib/api/client.ts`, ajouter à l'interface `MyCandidature`, après `statut` :

```ts
  /** 1 = formulaire antérieur à août 2026, 2 = formulaire courant. */
  form_version: number;
```

- [ ] **Étape 5.4 : Ajouter les libellés français**

Dans `candidature/messages/fr.json` :

Sous `wizard.step1`, ajouter :

```json
    "lieuNaissance": "Lieu de naissance (ville)"
```

Sous `wizard.step2`, **supprimer** la clé `lieuNaissance`.

Sous `wizard.step3`, remplacer les quatre libellés existants et ajouter les nouveaux :

```json
    "diplome": "Diplôme le plus élevé obtenu",
    "annee": "Année d'obtention du diplôme le plus élevé",
    "specialiteDiplome": "Spécialité du diplôme le plus élevé",
    "institut": "Établissement de délivrance du diplôme le plus élevé",
    "diplomeRequis": "Diplôme requis",
    "anneeDiplomeRequis": "Année d'obtention du diplôme requis",
    "domaineDiplomeRequis": "Domaine du diplôme requis",
    "specialiteDiplomeRequis": "Spécialité du diplôme requis",
    "institutDiplomeRequis": "Établissement de délivrance du diplôme requis",
    "blocPlusEleve": "Diplôme le plus élevé obtenu",
    "blocRequis": "Diplôme requis pour l'admission"
```

Sous `wizard`, ajouter un objet `diplomes` :

```json
  "diplomes": {
    "sectionTitle": "Autres diplômes et formations",
    "sectionHint": "Facultatif. Vous pouvez laisser cette section vide.",
    "autresDiplomes": {
      "legend": "Diplôme académique complémentaire",
      "add": "Ajouter un diplôme",
      "remove": "Supprimer le diplôme {index}",
      "removed": "Diplôme supprimé.",
      "added": "Diplôme ajouté.",
      "max": "Vous ne pouvez pas ajouter plus de {max} diplômes.",
      "intitule": "Intitulé du diplôme",
      "etablissement": "Établissement d'obtention",
      "annee": "Année d'obtention"
    },
    "formationsPro": {
      "legend": "Formation professionnelle",
      "add": "Ajouter une formation",
      "remove": "Supprimer la formation {index}",
      "removed": "Formation supprimée.",
      "added": "Formation ajoutée.",
      "max": "Vous ne pouvez pas ajouter plus de {max} formations.",
      "centre": "Centre de formation",
      "qualification": "Qualification obtenue",
      "annee": "Année de formation"
    }
  }
```

Sous `dossier.fields`, ajuster et ajouter :

```json
    "diplome_obtenu": "Diplôme le plus élevé obtenu",
    "institut": "Établissement de délivrance du diplôme le plus élevé",
    "specialite_diplome": "Spécialité du diplôme le plus élevé",
    "annee_diplome": "Année d'obtention du diplôme le plus élevé",
    "diplome_requis": "Diplôme requis",
    "annee_diplome_requis": "Année d'obtention du diplôme requis",
    "domaine_diplome_requis": "Domaine du diplôme requis",
    "specialite_diplome_requis": "Spécialité du diplôme requis",
    "institut_diplome_requis": "Établissement de délivrance du diplôme requis",
    "autres_diplomes": "Autres diplômes académiques",
    "formations_professionnelles": "Formations professionnelles"
```

Sous `wizard.step5.fields`, ajouter :

```json
    "requiredDegree": "Diplôme requis",
    "requiredDegreeYear": "Année d'obtention du diplôme requis",
    "requiredDegreeDomain": "Domaine du diplôme requis",
    "requiredDegreeField": "Spécialité du diplôme requis",
    "requiredDegreeInstitution": "Établissement du diplôme requis",
    "otherDegrees": "Autres diplômes",
    "proTraining": "Formations professionnelles",
    "none": "Aucun"
```

- [ ] **Étape 5.5 : Ajouter les libellés anglais**

Dans `candidature/messages/en.json`, appliquer exactement la même structure de clés avec ces valeurs :

`wizard.step1.lieuNaissance` → `"Place of birth (town)"` · supprimer `wizard.step2.lieuNaissance`.

`wizard.step3` :

```json
    "diplome": "Highest qualification obtained",
    "annee": "Year the highest qualification was awarded",
    "specialiteDiplome": "Field of the highest qualification",
    "institut": "Institution that awarded the highest qualification",
    "diplomeRequis": "Required qualification",
    "anneeDiplomeRequis": "Year the required qualification was awarded",
    "domaineDiplomeRequis": "Field of study of the required qualification",
    "specialiteDiplomeRequis": "Specialisation of the required qualification",
    "institutDiplomeRequis": "Institution that awarded the required qualification",
    "blocPlusEleve": "Highest qualification obtained",
    "blocRequis": "Qualification required for admission"
```

`wizard.diplomes` :

```json
  "diplomes": {
    "sectionTitle": "Other qualifications and training",
    "sectionHint": "Optional. You may leave this section empty.",
    "autresDiplomes": {
      "legend": "Additional academic qualification",
      "add": "Add a qualification",
      "remove": "Remove qualification {index}",
      "removed": "Qualification removed.",
      "added": "Qualification added.",
      "max": "You cannot add more than {max} qualifications.",
      "intitule": "Qualification title",
      "etablissement": "Awarding institution",
      "annee": "Year awarded"
    },
    "formationsPro": {
      "legend": "Professional training",
      "add": "Add a training course",
      "remove": "Remove training course {index}",
      "removed": "Training course removed.",
      "added": "Training course added.",
      "max": "You cannot add more than {max} training courses.",
      "centre": "Training centre",
      "qualification": "Qualification obtained",
      "annee": "Year of training"
    }
  }
```

`dossier.fields` :

```json
    "diplome_obtenu": "Highest qualification obtained",
    "institut": "Institution that awarded the highest qualification",
    "specialite_diplome": "Field of the highest qualification",
    "annee_diplome": "Year the highest qualification was awarded",
    "diplome_requis": "Required qualification",
    "annee_diplome_requis": "Year the required qualification was awarded",
    "domaine_diplome_requis": "Field of study of the required qualification",
    "specialite_diplome_requis": "Specialisation of the required qualification",
    "institut_diplome_requis": "Institution that awarded the required qualification",
    "autres_diplomes": "Other academic qualifications",
    "formations_professionnelles": "Professional training"
```

`wizard.step5.fields` :

```json
    "requiredDegree": "Required qualification",
    "requiredDegreeYear": "Year the required qualification was awarded",
    "requiredDegreeDomain": "Field of study of the required qualification",
    "requiredDegreeField": "Specialisation of the required qualification",
    "requiredDegreeInstitution": "Institution that awarded the required qualification",
    "otherDegrees": "Other qualifications",
    "proTraining": "Professional training",
    "none": "None"
```

- [ ] **Étape 5.6 : Vérifier que les deux catalogues ont exactement les mêmes clés**

```bash
cd candidature && python3 -c "
import json
def keys(o, p=''):
    out=set()
    for k,v in o.items():
        kk=f'{p}.{k}' if p else k
        out.add(kk)
        if isinstance(v, dict): out |= keys(v, kk)
    return out
fr=keys(json.load(open('messages/fr.json'))); en=keys(json.load(open('messages/en.json')))
print('FR seulement:', sorted(fr-en)); print('EN seulement:', sorted(en-fr))
"
```

Attendu : les deux listes sont vides.

- [ ] **Étape 5.7 : Typecheck**

```bash
cd candidature && pnpm typecheck
```

Attendu : des erreurs subsistent tant que `wizard/types.ts` n'est pas étendu (tâche 7) — **c'est normal à ce stade**. Vérifier qu'aucune erreur ne concerne `lib/diplomes/rows.ts`, `lib/dossier/options.ts` ni `lib/api/types.ts`.

- [ ] **Étape 5.8 : Commit**

```bash
git add candidature/lib/diplomes/rows.ts candidature/lib/dossier/options.ts \
        candidature/lib/api/types.ts candidature/lib/api/client.ts \
        candidature/messages/fr.json candidature/messages/en.json
git commit -m "feat(candidature): types, options et libellés du diplôme requis"
```

---

### Tâche 6 : Composant de lignes répétables

**Fichiers :**
- Créer : `candidature/components/RepeatableRows/index.tsx`
- Créer : `candidature/components/diplomes/AutresDiplomesEtFormations.tsx`

**Interfaces :**
- Consomme : `RepeatableColumn`, `MAX_DIPLOME_ROWS`, `AutreDiplomeRow`, `FormationProRow`, `EMPTY_AUTRE_DIPLOME`, `EMPTY_FORMATION_PRO`, `AUTRE_DIPLOME_COLUMNS`, `FORMATION_PRO_COLUMNS` (tâche 5).
- Produit : `<RepeatableRows<T> />` et `<AutresDiplomesEtFormations />` avec la signature de props détaillée ci-dessous. Consommé par les tâches 8 et 9.

- [ ] **Étape 6.1 : Écrire le composant générique**

Créer `candidature/components/RepeatableRows/index.tsx` :

```tsx
'use client';

import { useRef, useState } from 'react';

import type { RepeatableColumn } from '@/lib/diplomes/rows';

export interface RepeatableRowsProps<T extends object> {
  /** Intitulé du <fieldset>. */
  legend: string;
  addLabel: string;
  /** Reçoit le rang affiché (1-based) : « Supprimer le diplôme 2 ». */
  removeLabel: (position: number) => string;
  addedAnnouncement: string;
  removedAnnouncement: string;
  maxReachedMessage: string;
  columns: ReadonlyArray<RepeatableColumn<T>>;
  /** Libellé de chaque cellule, résolu par l'appelant (next-intl). */
  columnLabel: (column: RepeatableColumn<T>) => string;
  rows: readonly T[];
  emptyRow: T;
  max: number;
  /** Préfixe des clés d'erreur : `autres_diplomes` → `autres_diplomes.0.intitule`. */
  fieldName: string;
  errors: Record<string, string | undefined>;
  testIdPrefix: string;
  onChange: (rows: T[]) => void;
}

/**
 * Bloc de lignes répétables accessible : un <fieldset> par bloc, un <label>
 * par cellule, un bouton de suppression nommé sans ambiguïté et une région
 * `aria-live` qui annonce ajouts et suppressions.
 *
 * Aucune mutation : chaque ajout, modification ou suppression produit un
 * nouveau tableau.
 */
export function RepeatableRows<T extends object>({
  legend,
  addLabel,
  removeLabel,
  addedAnnouncement,
  removedAnnouncement,
  maxReachedMessage,
  columns,
  columnLabel,
  rows,
  emptyRow,
  max,
  fieldName,
  errors,
  testIdPrefix,
  onChange,
}: RepeatableRowsProps<T>): JSX.Element {
  const [announcement, setAnnouncement] = useState('');
  const containerRef = useRef<HTMLDivElement | null>(null);
  const atMax = rows.length >= max;

  const addRow = (): void => {
    if (atMax) {
      setAnnouncement(maxReachedMessage);
      return;
    }
    onChange([...rows, { ...emptyRow }]);
    setAnnouncement(addedAnnouncement);
    requestAnimationFrame(() => {
      const inputs = containerRef.current?.querySelectorAll<HTMLInputElement>('input');
      inputs?.[(rows.length * columns.length)]?.focus();
    });
  };

  const removeRow = (index: number): void => {
    onChange(rows.filter((_, i) => i !== index));
    setAnnouncement(removedAnnouncement);
  };

  const patchRow = (index: number, key: keyof T & string, value: string): void => {
    const column = columns.find((c) => c.key === key);
    const parsed =
      column?.type === 'year' ? (value === '' ? '' : Number(value)) : value;
    onChange(rows.map((row, i) => (i === index ? { ...row, [key]: parsed } : row)));
  };

  return (
    <fieldset className="rounded-md border border-[#E4DCEE] bg-[#FAF7FF] p-4">
      <legend className="px-1 text-sm font-semibold text-[#4A2E67]">{legend}</legend>

      <div ref={containerRef} className="space-y-4">
        {rows.map((row, index) => (
          <div
            key={index}
            data-testid={`${testIdPrefix}-row-${index}`}
            className="grid gap-3 rounded-md border border-[#E4DCEE] bg-white p-3 md:grid-cols-[1fr_1fr_8rem_auto]"
          >
            {columns.map((column) => {
              const errorKey = `${fieldName}.${index}.${column.key}`;
              const error = errors[errorKey];
              const raw = row[column.key] as unknown;
              return (
                <label key={column.key} className="block" data-field-error={Boolean(error)}>
                  <span className="mb-1 block text-xs font-medium text-[#333333]">
                    {columnLabel(column)}
                  </span>
                  <input
                    data-testid={`${testIdPrefix}-${index}-${column.key}`}
                    type={column.type === 'year' ? 'number' : 'text'}
                    inputMode={column.type === 'year' ? 'numeric' : undefined}
                    min={column.type === 'year' ? 1950 : undefined}
                    max={column.type === 'year' ? new Date().getFullYear() : undefined}
                    value={raw === null || raw === undefined ? '' : String(raw)}
                    aria-invalid={Boolean(error)}
                    onChange={(event) => patchRow(index, column.key, event.target.value)}
                    className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
                  />
                  {error && (
                    <span role="alert" className="mt-1 block text-xs text-red-600">
                      {error}
                    </span>
                  )}
                </label>
              );
            })}

            <div className="flex items-end">
              <button
                type="button"
                data-testid={`${testIdPrefix}-remove-${index}`}
                onClick={() => removeRow(index)}
                className="h-11 rounded-md border border-gray-300 px-3 text-sm text-[#333333] hover:border-red-600 hover:text-red-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#4A2E67]"
              >
                {removeLabel(index + 1)}
              </button>
            </div>
          </div>
        ))}
      </div>

      <button
        type="button"
        data-testid={`${testIdPrefix}-add`}
        onClick={addRow}
        disabled={atMax}
        className="mt-4 rounded-md border border-[#4A2E67] px-4 py-2 text-sm font-medium text-[#4A2E67] hover:bg-[#F4EFFA] disabled:cursor-not-allowed disabled:border-gray-300 disabled:text-gray-400"
      >
        {addLabel}
      </button>

      <p aria-live="polite" className="sr-only">
        {announcement}
      </p>
    </fieldset>
  );
}
```

- [ ] **Étape 6.2 : Écrire les deux blocs métier**

Créer `candidature/components/diplomes/AutresDiplomesEtFormations.tsx` :

```tsx
'use client';

import { useTranslations } from 'next-intl';

import { RepeatableRows } from '@/components/RepeatableRows';
import {
  AUTRE_DIPLOME_COLUMNS,
  EMPTY_AUTRE_DIPLOME,
  EMPTY_FORMATION_PRO,
  FORMATION_PRO_COLUMNS,
  MAX_DIPLOME_ROWS,
  type AutreDiplomeRow,
  type FormationProRow,
  type RepeatableColumn,
} from '@/lib/diplomes/rows';

export interface AutresDiplomesEtFormationsProps {
  autresDiplomes: readonly AutreDiplomeRow[];
  formationsProfessionnelles: readonly FormationProRow[];
  errors: Record<string, string | undefined>;
  onChangeAutresDiplomes: (rows: AutreDiplomeRow[]) => void;
  onChangeFormations: (rows: FormationProRow[]) => void;
}

/**
 * Section facultative « Autres diplômes et formations » — deux blocs répétables
 * indépendants. Partagée par le wizard d'inscription et l'édition du dossier :
 * une seule source de vérité pour le balisage, les libellés et l'accessibilité.
 */
export function AutresDiplomesEtFormations({
  autresDiplomes,
  formationsProfessionnelles,
  errors,
  onChangeAutresDiplomes,
  onChangeFormations,
}: AutresDiplomesEtFormationsProps): JSX.Element {
  const t = useTranslations('wizard.diplomes');

  return (
    <section className="space-y-4" data-testid="autres-diplomes-section">
      <div>
        <h3 className="font-heading text-lg font-semibold text-[#4A2E67]">{t('sectionTitle')}</h3>
        <p className="text-xs text-[#666]">{t('sectionHint')}</p>
      </div>

      <RepeatableRows<AutreDiplomeRow>
        legend={t('autresDiplomes.legend')}
        addLabel={t('autresDiplomes.add')}
        removeLabel={(position) => t('autresDiplomes.remove', { index: position })}
        addedAnnouncement={t('autresDiplomes.added')}
        removedAnnouncement={t('autresDiplomes.removed')}
        maxReachedMessage={t('autresDiplomes.max', { max: MAX_DIPLOME_ROWS })}
        columns={AUTRE_DIPLOME_COLUMNS}
        columnLabel={(column: RepeatableColumn<AutreDiplomeRow>) => t(column.labelKey)}
        rows={autresDiplomes}
        emptyRow={EMPTY_AUTRE_DIPLOME}
        max={MAX_DIPLOME_ROWS}
        fieldName="autres_diplomes"
        errors={errors}
        testIdPrefix="autres-diplomes"
        onChange={onChangeAutresDiplomes}
      />

      <RepeatableRows<FormationProRow>
        legend={t('formationsPro.legend')}
        addLabel={t('formationsPro.add')}
        removeLabel={(position) => t('formationsPro.remove', { index: position })}
        addedAnnouncement={t('formationsPro.added')}
        removedAnnouncement={t('formationsPro.removed')}
        maxReachedMessage={t('formationsPro.max', { max: MAX_DIPLOME_ROWS })}
        columns={FORMATION_PRO_COLUMNS}
        columnLabel={(column: RepeatableColumn<FormationProRow>) => t(column.labelKey)}
        rows={formationsProfessionnelles}
        emptyRow={EMPTY_FORMATION_PRO}
        max={MAX_DIPLOME_ROWS}
        fieldName="formations_professionnelles"
        errors={errors}
        testIdPrefix="formations-pro"
        onChange={onChangeFormations}
      />
    </section>
  );
}
```

- [ ] **Étape 6.3 : Lint et typecheck des nouveaux fichiers**

```bash
cd candidature && pnpm lint && pnpm typecheck
```

Attendu : aucune erreur ni avertissement provenant de `components/RepeatableRows/index.tsx` ou `components/diplomes/AutresDiplomesEtFormations.tsx`. Les erreurs restantes concernant `wizard/types.ts` sont attendues jusqu'à la tâche 7.

- [ ] **Étape 6.4 : Commit**

```bash
git add candidature/components/RepeatableRows/index.tsx \
        candidature/components/diplomes/AutresDiplomesEtFormations.tsx
git commit -m "feat(candidature): composant de lignes répétables pour les autres diplômes"
```

---

### Tâche 7 : Wizard — schémas et déplacement du lieu de naissance

**Fichiers :**
- Modifier : `candidature/components/wizard/types.ts`
- Modifier : `candidature/lib/validation/schemas.ts`
- Modifier : `candidature/components/wizard/WizardContainer.tsx`
- Modifier : `candidature/components/wizard/WizardStep1Identite.tsx`
- Modifier : `candidature/components/wizard/WizardStep2Coordonnees.tsx`
- Modifier : `candidature/tests/playwright/inscription-wizard.spec.ts`

**Interfaces :**
- Produit : `WizardErrors` exporté depuis `@/components/wizard/types`, consommé par toutes les étapes du wizard.
- Produit : `WizardData` étendu de `diplome_requis`, `annee_diplome_requis`, `domaine_diplome_requis`, `specialite_diplome_requis`, `institut_diplome_requis`, `autres_diplomes`, `formations_professionnelles`.

- [ ] **Étape 7.1 : Déplacer l'assertion Playwright (le test doit échouer)**

Dans `candidature/tests/playwright/inscription-wizard.spec.ts`, retirer de `fillStep2` la ligne :

```ts
  await page.getByLabel('Lieu de naissance (ville)').fill('Yaoundé');
```

et l'ajouter à la fin de `fillStep1` :

```ts
  await page.getByLabel('Lieu de naissance (ville)').fill('Yaoundé');
```

Ajouter également ce test dans le même fichier :

```ts
test('le lieu de naissance est saisi à l’étape 1 et absent de l’étape 2', async ({ page }) => {
  await setupReferenceMocks(page);
  await page.goto('/inscription');

  await expect(page.getByLabel('Lieu de naissance (ville)')).toBeVisible();

  await fillStep1(page);
  await page.getByTestId('wizard-next').click();

  await expect(page.getByTestId('wizard-step-2')).toBeVisible();
  await expect(page.getByLabel('Lieu de naissance (ville)')).toHaveCount(0);
});
```

- [ ] **Étape 7.2 : Lancer le test pour vérifier qu'il échoue**

```bash
cd candidature && pnpm test --grep "lieu de naissance est saisi"
```

Attendu : ÉCHEC — le champ n'est pas visible à l'étape 1.

- [ ] **Étape 7.3 : Étendre `WizardData` et introduire `WizardErrors`**

Dans `candidature/components/wizard/types.ts`, ajouter l'import :

```ts
import type { AutreDiplomeRow, FormationProRow } from '@/lib/diplomes/rows';
```

Dans l'interface `WizardData`, **déplacer** `lieu_naissance` du bloc « Step 2 » vers le bloc « Step 1 » (juste après `date_naissance`), et ajouter au bloc « Step 3 », après `annee_diplome` :

```ts
  diplome_requis: string;
  annee_diplome_requis: number | '';
  domaine_diplome_requis: string;
  specialite_diplome_requis: string;
  institut_diplome_requis: string;
  autres_diplomes: AutreDiplomeRow[];
  formations_professionnelles: FormationProRow[];
```

Dans `initialWizardData`, ajouter aux mêmes emplacements :

```ts
  diplome_requis: '',
  annee_diplome_requis: '',
  domaine_diplome_requis: '',
  specialite_diplome_requis: '',
  institut_diplome_requis: '',
  autres_diplomes: [],
  formations_professionnelles: [],
```

Ajouter en fin de fichier :

```ts
/**
 * Carte des erreurs du wizard. L'intersection conserve l'autocomplétion sur les
 * champs connus tout en autorisant les clés à chemin complet produites par les
 * blocs répétables (`autres_diplomes.0.intitule`).
 */
export type WizardErrors = Partial<Record<keyof WizardData, string>> & {
  [key: string]: string | undefined;
};
```

- [ ] **Étape 7.4 : Déplacer `lieu_naissance` dans les schémas zod**

Dans `candidature/lib/validation/schemas.ts` :

Ajouter à `step1Schema`, après la clé `date_naissance` :

```ts
  lieu_naissance: z.string().trim().min(1, requiredMessage).max(100),
```

Retirer la ligne `lieu_naissance: z.string().trim().min(1, requiredMessage).max(100),` de `step2Schema`.

- [ ] **Étape 7.5 : Adapter le conteneur du wizard**

Dans `candidature/components/wizard/WizardContainer.tsx` :

Remplacer l'import de type :

```ts
import { initialWizardData, type WizardData, type WizardErrors, type WizardServerActionResult } from './types';
```

Remplacer la déclaration d'état des erreurs :

```ts
  const [errors, setErrors] = useState<WizardErrors>({});
```

Dans `validateStep`, remplacer la construction de la carte d'erreurs par :

```ts
    const next: WizardErrors = {};
    for (const issue of result.error.issues) {
      // Chemin complet pour les lignes répétables (`autres_diplomes.0.intitule`),
      // clé simple sinon.
      const key = issue.path.length > 1 ? issue.path.join('.') : String(issue.path[0]);
      if (!next[key]) {
        next[key] = issue.message;
      }
    }
    setErrors(next);
```

Dans `showStep4StrictErrors`, remplacer le type local :

```ts
    const strictErrors: WizardErrors = {};
```

Remplacer la ligne `mergedErrors` :

```ts
  const mergedErrors: WizardErrors = { ...errors, ...(serverErrors ?? {}) };
```

Dans `getServerErrorStep`, déplacer `'lieu_naissance'` de `step2Fields` vers `step1Fields`, et ajouter à `step3Fields` :

```ts
    'diplome_requis', 'annee_diplome_requis', 'domaine_diplome_requis',
    'specialite_diplome_requis', 'institut_diplome_requis',
    'autres_diplomes', 'formations_professionnelles',
```

Enfin, dans `getServerErrorStep`, remplacer la comparaison exacte par une comparaison qui tolère les chemins complets — sans quoi une erreur `autres_diplomes.0.intitule` ne renverrait pas à l'étape 3 :

```ts
  const root = (key: string): string => key.split('.')[0] ?? key;
  const keys = Object.keys(errors).map(root);
```

- [ ] **Étape 7.6 : Ajouter le champ à l'étape 1**

Dans `candidature/components/wizard/WizardStep1Identite.tsx`, remplacer le type des erreurs dans `WizardStep1Props` :

```ts
  errors: WizardErrors;
```

et l'import :

```ts
import type { WizardData, WizardErrors } from './types';
```

Puis insérer, entre la grille à trois colonnes qui contient « Date de naissance » et la grille à deux colonnes qui contient « Situation matrimoniale » :

```tsx
      <Field label={t('lieuNaissance')} error={errors.lieu_naissance} required>
        <input
          data-testid="step1-lieu-naissance"
          type="text"
          value={data.lieu_naissance}
          onChange={(e) => onChange({ lieu_naissance: e.target.value })}
          className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
        />
      </Field>
```

- [ ] **Étape 7.7 : Retirer le champ de l'étape 2**

Dans `candidature/components/wizard/WizardStep2Coordonnees.tsx`, supprimer intégralement le bloc :

```tsx
      <Field label={t('lieuNaissance')} error={errors.lieu_naissance} required>
        …
      </Field>
```

Mettre aussi à jour le type des erreurs et son import, comme à l'étape 7.6.

- [ ] **Étape 7.8 : Aligner le type des erreurs sur les étapes 3, 4 et 5**

Dans `WizardStep3Diplome.tsx`, `WizardStep4Engagement.tsx` et `WizardStep5Review.tsx`, remplacer chaque occurrence de :

```ts
  errors: Partial<Record<keyof WizardData, string>>;
```

par :

```ts
  errors: WizardErrors;
```

et ajouter `WizardErrors` à l'import de `./types` dans chacun de ces fichiers.

- [ ] **Étape 7.9 : Lancer les tests**

```bash
cd candidature && pnpm typecheck && pnpm test --grep "lieu de naissance est saisi"
```

Attendu : typecheck sans erreur, test Playwright au vert.

- [ ] **Étape 7.10 : Lancer toute la suite du wizard**

```bash
cd candidature && pnpm test --grep "wizard"
```

Attendu : SUCCÈS. Le parcours complet passe toujours avec le lieu de naissance saisi à l'étape 1.

- [ ] **Étape 7.11 : Commit**

```bash
git add candidature/components/wizard/ candidature/lib/validation/schemas.ts \
        candidature/tests/playwright/inscription-wizard.spec.ts
git commit -m "feat(wizard): remonter le lieu de naissance à l'étape 1"
```

---

### Tâche 8 : Wizard — étape 3 et récapitulatif

**Fichiers :**
- Modifier : `candidature/components/wizard/WizardStep3Diplome.tsx`
- Modifier : `candidature/lib/validation/schemas.ts`
- Modifier : `candidature/components/wizard/WizardStep5Review.tsx`
- Modifier : `candidature/app/[locale]/inscription/actions.ts`
- Créer : `candidature/tests/playwright/diplome-requis.spec.ts`

**Interfaces :**
- Consomme : `AutresDiplomesEtFormations` (tâche 6), `DIPLOME_REQUIS_OPTIONS`, `DOMAINE_DIPLOME_OPTIONS`, `needsSpecialiteDiplomeRequis` (tâche 5), `WizardErrors` (tâche 7).
- Produit : les `data-testid` `step3-diplome-requis`, `step3-annee-diplome-requis`, `step3-domaine-diplome-requis`, `step3-specialite-diplome-requis`, `step3-institut-diplome-requis`, consommés par les tests de la tâche 10.

- [ ] **Étape 8.1 : Écrire le test E2E de l'étape 3 (il doit échouer)**

Créer `candidature/tests/playwright/diplome-requis.spec.ts` :

```ts
import { test, expect, Page } from '@playwright/test';

const PAYS_FIXTURE = [
  { code_iso: 'CM', nom: 'Cameroun', indicatif: '+237' },
  { code_iso: 'FR', nom: 'France', indicatif: '+33' },
];

const SPECIALITES_FIXTURE = [
  { slug: 'fiscalite-finance-comptabilite-publique', label: 'Fiscalité - Finance - Comptabilité Publique' },
];

const REGIONS_FIXTURE = [
  { code: 'CENTRE', nom: 'Centre', quota_admission: 0.15, chef_lieu: 'Yaoundé', order: 2 },
];

const DEPARTEMENTS_CENTRE = [
  { code: 'Mfoundi', nom: 'Mfoundi', chef_lieu: 'Yaoundé', region_code: 'CENTRE' },
];

async function setupReferenceMocks(page: Page): Promise<void> {
  await page.route('**/v1/reference/pays', (r) =>
    r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: PAYS_FIXTURE }) }),
  );
  await page.route('**/v1/reference/specialites', (r) =>
    r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: SPECIALITES_FIXTURE }) }),
  );
  await page.route('**/v1/reference/regions-cameroun', (r) =>
    r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: REGIONS_FIXTURE }) }),
  );
  await page.route('**/v1/reference/departements-cameroun**', (r) =>
    r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: DEPARTEMENTS_CENTRE }) }),
  );
}

async function gotoStep3(page: Page): Promise<void> {
  await setupReferenceMocks(page);
  await page.goto('/inscription');

  await page.getByTestId('step1-specialite').click();
  await page.getByRole('option', { name: /Fiscalité/i }).click();
  await page.getByTestId('step1-prenom').fill('Jean');
  await page.getByTestId('step1-nom').fill('Dupont');
  await page.getByTestId('step1-date-naissance').fill('1990-06-15');
  await page.getByTestId('step1-lieu-naissance').fill('Yaoundé');
  await page.getByTestId('wizard-next').click();

  await page.getByTestId('step2-adresse').fill('BP 1234 Yaoundé');
  await page.getByLabel('Ville de résidence').fill('Yaoundé');
  await page.getByTestId('step2-phone-number').fill('691234567');
  await page.getByTestId('region-select').click();
  await page.getByRole('option', { name: 'Centre' }).click();
  await page.getByTestId('departement-select').click();
  await page.getByRole('option', { name: 'Mfoundi' }).click();
  await page.getByLabel('Adresse e-mail personnelle').fill('jean.dupont@example.com');
  await page.getByTestId('wizard-next').click();

  await expect(page.getByTestId('wizard-step-3')).toBeVisible();
}

test('la spécialité du diplôme requis n’apparaît que pour le domaine Autres', async ({ page }) => {
  await gotoStep3(page);

  await page.getByTestId('step3-domaine-diplome-requis').selectOption('droit');
  await expect(page.getByTestId('step3-specialite-diplome-requis')).toHaveCount(0);

  await page.getByTestId('step3-domaine-diplome-requis').selectOption('autres');
  await expect(page.getByTestId('step3-specialite-diplome-requis')).toBeVisible();

  await page.getByTestId('step3-specialite-diplome-requis').fill('Sciences politiques');
  await page.getByTestId('step3-domaine-diplome-requis').selectOption('gestion');
  await page.getByTestId('step3-domaine-diplome-requis').selectOption('autres');

  await expect(page.getByTestId('step3-specialite-diplome-requis')).toHaveValue('');
});

test('le diplôme requis est obligatoire pour passer à l’étape suivante', async ({ page }) => {
  await gotoStep3(page);

  await page.getByTestId('wizard-next').click();

  await expect(page.getByTestId('wizard-step-3')).toBeVisible();
  await expect(page.getByTestId('step3-diplome-requis')).toHaveAttribute('data-field-error', 'true')
    .catch(async () => {
      // L'attribut est porté par le <label> parent.
      await expect(
        page.locator('[data-field-error="true"]').filter({ hasText: 'Diplôme requis' }).first(),
      ).toBeVisible();
    });
});

test('les blocs répétables ajoutent et suppriment des lignes', async ({ page }) => {
  await gotoStep3(page);

  await expect(page.getByTestId('autres-diplomes-row-0')).toHaveCount(0);

  await page.getByTestId('autres-diplomes-add').click();
  await expect(page.getByTestId('autres-diplomes-row-0')).toBeVisible();

  await page.getByTestId('autres-diplomes-0-intitule').fill('DESS Finances publiques');
  await page.getByTestId('autres-diplomes-0-etablissement').fill('ENAM');
  await page.getByTestId('autres-diplomes-0-annee').fill('2019');

  await page.getByTestId('autres-diplomes-add').click();
  await expect(page.getByTestId('autres-diplomes-row-1')).toBeVisible();

  await page.getByTestId('autres-diplomes-remove-1').click();
  await expect(page.getByTestId('autres-diplomes-row-1')).toHaveCount(0);
  await expect(page.getByTestId('autres-diplomes-0-intitule')).toHaveValue('DESS Finances publiques');

  await page.getByTestId('formations-pro-add').click();
  await expect(page.getByTestId('formations-pro-0-centre')).toBeVisible();
  await page.getByTestId('formations-pro-remove-0').click();
  await expect(page.getByTestId('formations-pro-row-0')).toHaveCount(0);
});

test('une ligne incomplète bloque le passage à l’étape suivante', async ({ page }) => {
  await gotoStep3(page);

  await page.getByTestId('step3-diplome-obtenu').selectOption({ label: 'Master' }).catch(() => undefined);
  await page.getByTestId('step3-diplome-requis').selectOption('master');
  await page.getByTestId('step3-annee-diplome-requis').fill('2012');
  await page.getByTestId('step3-domaine-diplome-requis').selectOption('droit');

  await page.getByTestId('autres-diplomes-add').click();
  await page.getByTestId('autres-diplomes-0-intitule').fill('DESS');

  await page.getByTestId('wizard-next').click();

  await expect(page.getByTestId('wizard-step-3')).toBeVisible();
  await expect(page.getByTestId('autres-diplomes-0-etablissement')).toHaveAttribute('aria-invalid', 'true');
});
```

- [ ] **Étape 8.2 : Lancer les tests pour vérifier qu'ils échouent**

```bash
cd candidature && pnpm test tests/playwright/diplome-requis.spec.ts
```

Attendu : ÉCHEC sur les quatre tests — les champs n'existent pas.

- [ ] **Étape 8.3 : Étendre le schéma zod de l'étape 3**

Dans `candidature/lib/validation/schemas.ts`, ajouter avant `step3Schema` :

```ts
const currentYear = new Date().getFullYear();

const anneeObtentionSchema = z
  .number({ error: 'Année invalide' })
  .int('Année invalide')
  .min(1950, 'Année invalide')
  .max(currentYear, "L'année ne peut pas être dans le futur.");

export const autreDiplomeRowSchema = z.object({
  intitule: z.string().trim().min(1, requiredMessage).max(150),
  etablissement: z.string().trim().min(1, requiredMessage).max(150),
  annee: anneeObtentionSchema,
});

export const formationProRowSchema = z.object({
  centre: z.string().trim().min(1, requiredMessage).max(150),
  qualification: z.string().trim().min(1, requiredMessage).max(150),
  annee: anneeObtentionSchema,
});
```

Ajouter dans l'objet de `step3Schema`, après `annee_diplome` :

```ts
  diplome_requis: z.enum(['licence-bachelor', 'master'], { error: requiredMessage }),
  annee_diplome_requis: anneeObtentionSchema,
  domaine_diplome_requis: z.enum(['droit', 'economie', 'gestion', 'autres'], { error: requiredMessage }),
  specialite_diplome_requis: z.string().trim().max(100).optional().nullable(),
  institut_diplome_requis: z.string().trim().min(1, requiredMessage).max(150),
  autres_diplomes: z.array(autreDiplomeRowSchema).max(10),
  formations_professionnelles: z.array(formationProRowSchema).max(10),
```

Ajouter dans le `superRefine` de `step3Schema`, en tête du callback :

```ts
  if (data.domaine_diplome_requis === 'autres' && !data.specialite_diplome_requis?.trim()) {
    context.addIssue({
      code: 'custom',
      message: requiredMessage,
      path: ['specialite_diplome_requis'],
    });
  }
```

- [ ] **Étape 8.4 : Réécrire l'étape 3**

Dans `candidature/components/wizard/WizardStep3Diplome.tsx`, ajouter les imports :

```ts
import { AutresDiplomesEtFormations } from '@/components/diplomes/AutresDiplomesEtFormations';
import {
  DIPLOME_REQUIS_OPTIONS,
  DOMAINE_DIPLOME_OPTIONS,
  MOYENS_CONNAISSANCE,
  STATUT_ACTUEL_OPTIONS,
  isPublicEmploymentStatus,
  needsEmployer,
  needsSpecialiteDiplomeRequis,
} from '@/lib/dossier/options';
```

Remplacer les deux grilles existantes qui portent `diplome_obtenu`, `annee_diplome`, `institut` et `specialite_diplome` par :

```tsx
      {/* Bloc 1 — diplôme le plus élevé obtenu (champs historiques). */}
      <div className="space-y-4">
        <h3 className="font-heading text-lg font-semibold text-[#4A2E67]">{t('blocPlusEleve')}</h3>
        <div className="grid gap-4 md:grid-cols-2">
          <Field label={t('diplome')} error={errors.diplome_obtenu} required>
            <DiplomeSelect
              diplomes={diplomes}
              value={data.diplome_obtenu}
              onChange={(v) => onChange({ diplome_obtenu: v })}
              error={undefined}
            />
          </Field>
          <Field label={t('annee')} error={errors.annee_diplome} required>
            <input
              data-testid="step3-annee-diplome"
              type="number"
              inputMode="numeric"
              min={1950}
              max={new Date().getFullYear()}
              value={data.annee_diplome}
              onChange={(e) =>
                onChange({ annee_diplome: e.target.value === '' ? '' : Number(e.target.value) })
              }
              className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
            />
          </Field>
        </div>
        <div className="grid gap-4 md:grid-cols-2">
          <Field label={t('specialiteDiplome')} error={errors.specialite_diplome} required>
            <input
              data-testid="step3-specialite-diplome"
              type="text"
              value={data.specialite_diplome}
              onChange={(e) => onChange({ specialite_diplome: e.target.value })}
              className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
            />
          </Field>
          <Field label={t('institut')} error={errors.institut} required>
            <InstitutSelect
              universites={universites}
              value={data.institut}
              onChange={(v) => onChange({ institut: v })}
              error={undefined}
            />
          </Field>
        </div>
      </div>

      {/* Bloc 2 — diplôme requis pour l'admission. */}
      <div className="space-y-4 border-t border-gray-100 pt-5">
        <h3 className="font-heading text-lg font-semibold text-[#4A2E67]">{t('blocRequis')}</h3>
        <div className="grid gap-4 md:grid-cols-2">
          <Field label={t('diplomeRequis')} error={errors.diplome_requis} required>
            <select
              data-testid="step3-diplome-requis"
              value={data.diplome_requis}
              onChange={(e) => onChange({ diplome_requis: e.target.value })}
              className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
            >
              <option value="">{to('choose')}</option>
              {DIPLOME_REQUIS_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </select>
          </Field>
          <Field label={t('anneeDiplomeRequis')} error={errors.annee_diplome_requis} required>
            <input
              data-testid="step3-annee-diplome-requis"
              type="number"
              inputMode="numeric"
              min={1950}
              max={new Date().getFullYear()}
              value={data.annee_diplome_requis}
              onChange={(e) =>
                onChange({
                  annee_diplome_requis: e.target.value === '' ? '' : Number(e.target.value),
                })
              }
              className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
            />
          </Field>
        </div>

        <Field label={t('domaineDiplomeRequis')} error={errors.domaine_diplome_requis} required>
          <select
            data-testid="step3-domaine-diplome-requis"
            value={data.domaine_diplome_requis}
            onChange={(e) => {
              const domaine = e.target.value;
              // Quitter « Autres » efface la spécialité : sans cela, une valeur
              // masquée resterait enregistrée et s'imprimerait sur le récépissé.
              onChange(
                needsSpecialiteDiplomeRequis(domaine)
                  ? { domaine_diplome_requis: domaine }
                  : { domaine_diplome_requis: domaine, specialite_diplome_requis: '' },
              );
            }}
            className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
          >
            <option value="">{to('choose')}</option>
            {DOMAINE_DIPLOME_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>{option.label}</option>
            ))}
          </select>
        </Field>

        {needsSpecialiteDiplomeRequis(data.domaine_diplome_requis) && (
          <Field
            label={t('specialiteDiplomeRequis')}
            error={errors.specialite_diplome_requis}
            required
          >
            <input
              data-testid="step3-specialite-diplome-requis"
              type="text"
              value={data.specialite_diplome_requis}
              onChange={(e) => onChange({ specialite_diplome_requis: e.target.value })}
              className="h-11 w-full rounded-md border border-gray-300 px-3 text-sm focus:border-[#4A2E67] focus:outline-none focus:ring-2 focus:ring-[#4A2E67]/30"
            />
          </Field>
        )}

        <Field label={t('institutDiplomeRequis')} error={errors.institut_diplome_requis} required>
          <InstitutSelect
            universites={universites}
            value={data.institut_diplome_requis}
            onChange={(v) => onChange({ institut_diplome_requis: v })}
            error={errors.institut_diplome_requis}
          />
        </Field>
      </div>

      {/* Bloc 3 — autres diplômes et formations, facultatif. */}
      <div className="border-t border-gray-100 pt-5">
        <AutresDiplomesEtFormations
          autresDiplomes={data.autres_diplomes}
          formationsProfessionnelles={data.formations_professionnelles}
          errors={errors}
          onChangeAutresDiplomes={(rows) => onChange({ autres_diplomes: rows })}
          onChangeFormations={(rows) => onChange({ formations_professionnelles: rows })}
        />
      </div>

      <div className="border-t border-gray-100 pt-5" />
```

> `InstitutSelect` porte un `testId` codé en dur (`step3-institut`). Le rendre paramétrable : ajouter une prop optionnelle `testId?: string` à `InstitutSelectProps`, la transmettre à `SearchableSelect` avec `step3-institut` en valeur par défaut, et passer `testId="step3-institut-requis"` pour le second usage. Sans cela, deux éléments partageraient le même `data-testid`.

- [ ] **Étape 8.5 : Étendre le récapitulatif de l'étape 5**

Dans `candidature/components/wizard/WizardStep5Review.tsx`, après la ligne `<ReviewRow label={t('fields.gradYear')} value={String(data.annee_diplome)} />`, ajouter :

```tsx
        <ReviewRow
          label={t('fields.requiredDegree')}
          value={
            DIPLOME_REQUIS_OPTIONS.find((o) => o.value === data.diplome_requis)?.label ?? ''
          }
        />
        <ReviewRow
          label={t('fields.requiredDegreeYear')}
          value={data.annee_diplome_requis === '' ? '' : String(data.annee_diplome_requis)}
        />
        <ReviewRow
          label={t('fields.requiredDegreeDomain')}
          value={
            DOMAINE_DIPLOME_OPTIONS.find((o) => o.value === data.domaine_diplome_requis)?.label ?? ''
          }
        />
        {needsSpecialiteDiplomeRequis(data.domaine_diplome_requis) && (
          <ReviewRow
            label={t('fields.requiredDegreeField')}
            value={data.specialite_diplome_requis}
          />
        )}
        <ReviewRow
          label={t('fields.requiredDegreeInstitution')}
          value={data.institut_diplome_requis}
        />
        <ReviewRow
          label={t('fields.otherDegrees')}
          value={
            data.autres_diplomes.length === 0
              ? t('fields.none')
              : data.autres_diplomes
                  .map((d) => `${d.intitule} — ${d.etablissement} (${d.annee})`)
                  .join(' · ')
          }
        />
        <ReviewRow
          label={t('fields.proTraining')}
          value={
            data.formations_professionnelles.length === 0
              ? t('fields.none')
              : data.formations_professionnelles
                  .map((f) => `${f.qualification} — ${f.centre} (${f.annee})`)
                  .join(' · ')
          }
        />
```

et l'import :

```ts
import {
  DIPLOME_REQUIS_OPTIONS,
  DOMAINE_DIPLOME_OPTIONS,
  needsSpecialiteDiplomeRequis,
} from '@/lib/dossier/options';
```

- [ ] **Étape 8.6 : Envoyer les nouveaux champs à la soumission**

Dans `candidature/app/[locale]/inscription/actions.ts`, ajouter au payload de `putApplicationsMe`, après `annee_diplome` :

```ts
      diplome_requis: payload.diplome_requis || null,
      annee_diplome_requis:
        typeof payload.annee_diplome_requis === 'number' ? payload.annee_diplome_requis : undefined,
      domaine_diplome_requis: payload.domaine_diplome_requis || null,
      specialite_diplome_requis: payload.specialite_diplome_requis || null,
      institut_diplome_requis: payload.institut_diplome_requis || null,
      autres_diplomes: payload.autres_diplomes.filter((row) => !isRowEmpty(row)),
      formations_professionnelles: payload.formations_professionnelles.filter(
        (row) => !isRowEmpty(row),
      ),
```

et l'import :

```ts
import { isRowEmpty } from '@/lib/diplomes/rows';
```

- [ ] **Étape 8.7 : Lancer les tests**

```bash
cd candidature && pnpm typecheck && pnpm test tests/playwright/diplome-requis.spec.ts
```

Attendu : typecheck sans erreur, quatre tests au vert.

- [ ] **Étape 8.8 : Lancer toute la suite candidature**

```bash
cd candidature && pnpm lint && pnpm test
```

Attendu : SUCCÈS complet.

- [ ] **Étape 8.9 : Commit**

```bash
git add candidature/components/wizard/ candidature/components/InstitutSelect/ \
        candidature/lib/validation/schemas.ts \
        candidature/app/\[locale\]/inscription/actions.ts \
        candidature/tests/playwright/diplome-requis.spec.ts
git commit -m "feat(wizard): bloc diplôme requis et autres diplômes à l'étape 3"
```

---

### Tâche 9 : Édition du dossier

**Fichiers :**
- Créer : `candidature/components/DossierEditionForm/primitives.tsx`
- Créer : `candidature/components/DossierEditionForm/SectionDiplome.tsx`
- Modifier : `candidature/components/DossierEditionForm/index.tsx`
- Modifier : `candidature/lib/dossier/editableFields.ts`
- Modifier : `candidature/lib/validation/submittable.ts`
- Modifier : `candidature/components/dossier/DossierCompleteness.tsx`
- Modifier : `candidature/components/dossier/DossierProfileSummary.tsx`
- Modifier : `candidature/tests/playwright/dossier-edition.spec.ts`

**Interfaces :**
- Consomme : `AutresDiplomesEtFormations` (tâche 6), options et types (tâche 5), contrat API `form_version` (tâche 2).
- Produit : `Card`, `Field`, `inputCls`, `SectionPropsBase` exportés depuis `@/components/DossierEditionForm/primitives`.
- Produit : `EditableValue` exporté depuis `@/lib/dossier/editableFields`.

- [ ] **Étape 9.1 : Écrire le test E2E d'édition (il doit échouer)**

Ajouter à `candidature/tests/playwright/dossier-edition.spec.ts`, en suivant le motif de mock déjà présent dans ce fichier pour `GET /v1/applications/me` :

```ts
test('un dossier v2 affiche le bloc diplôme requis', async ({ page }) => {
  await mockDossier(page, { form_version: 2 });
  await page.goto('/dossier/edition');

  await expect(page.getByTestId('edition-section-diplome')).toBeVisible();
  await expect(page.getByTestId('edit-diplome-requis')).toBeVisible();
  await expect(page.getByTestId('autres-diplomes-add')).toBeVisible();
});

test('un dossier v1 conserve l’ancien formulaire', async ({ page }) => {
  await mockDossier(page, { form_version: 1 });
  await page.goto('/dossier/edition');

  await expect(page.getByTestId('edition-section-diplome')).toBeVisible();
  await expect(page.getByTestId('edit-diplome-requis')).toHaveCount(0);
  await expect(page.getByTestId('autres-diplomes-add')).toHaveCount(0);
});
```

> Adapter `mockDossier` au helper de mock déjà utilisé dans ce fichier — ne pas en introduire un second. Si le helper existant n'accepte pas d'`overrides`, lui en ajouter un paramètre plutôt que dupliquer.

- [ ] **Étape 9.2 : Lancer les tests pour vérifier qu'ils échouent**

```bash
cd candidature && pnpm test tests/playwright/dossier-edition.spec.ts
```

Attendu : ÉCHEC sur « un dossier v2 affiche le bloc diplôme requis ».

- [ ] **Étape 9.3 : Élargir le type des champs éditables**

Dans `candidature/lib/dossier/editableFields.ts` :

Ajouter l'import :

```ts
import type { AutreDiplomeRow, FormationProRow } from '@/lib/diplomes/rows';
```

Ajouter à `EDITABLE_FIELDS`, dans la section « Diplôme & profession », après `'annee_diplome',` :

```ts
  'diplome_requis',
  'annee_diplome_requis',
  'domaine_diplome_requis',
  'specialite_diplome_requis',
  'institut_diplome_requis',
  'autres_diplomes',
  'formations_professionnelles',
```

Remplacer le type de valeur :

```ts
export type EditableValue =
  | string
  | number
  | null
  | AutreDiplomeRow[]
  | FormationProRow[];

export type EditableFields = Partial<Record<EditableField, EditableValue>>;
```

Ajouter à `SECTION_OF_FIELD` :

```ts
  diplome_requis: 'diplome',
  annee_diplome_requis: 'diplome',
  domaine_diplome_requis: 'diplome',
  specialite_diplome_requis: 'diplome',
  institut_diplome_requis: 'diplome',
  autres_diplomes: 'diplome',
  formations_professionnelles: 'diplome',
```

- [ ] **Étape 9.4 : Extraire les primitives partagées**

Créer `candidature/components/DossierEditionForm/primitives.tsx` en y déplaçant **à l'identique** depuis `index.tsx` : le composant `Card`, le composant `Field`, la constante `inputCls` et l'interface `SectionPropsBase`. Les exporter, ajouter `'use client';` en tête, importer ce dont ils dépendent :

```tsx
'use client';

import type { EditableField, EditableValue } from '@/lib/dossier/editableFields';
```

puis adapter `SectionPropsBase` et déplacer `FormState` depuis `index.tsx` :

```tsx
export interface SectionPropsBase {
  form: FormState;
  errors: Partial<Record<EditableField, string>> & Record<string, string | undefined>;
  setField: (field: EditableField, value: EditableValue) => void;
}

export type FormState = Record<EditableField, EditableValue>;
```

Dans `index.tsx`, supprimer les définitions déplacées et les importer :

```ts
import { Card, Field, inputCls, type FormState, type SectionPropsBase } from './primitives';
```

- [ ] **Étape 9.5 : Extraire et étendre la section diplôme**

Créer `candidature/components/DossierEditionForm/SectionDiplome.tsx` en y déplaçant la fonction `SectionDiplome` telle qu'elle existe dans `index.tsx`, puis :

- ajouter `'use client';` en tête et les imports nécessaires (`useTranslations`, `DiplomeSelect`, `EmployeurPublicSelect`, `InstitutSelect`, `AutresDiplomesEtFormations`, options, primitives) ;
- ajouter une prop `formVersion: number` à sa signature ;
- après la grille existante `institut` / `specialite_diplome`, insérer le bloc conditionnel :

```tsx
      {formVersion >= 2 && (
        <>
          <div className="grid gap-4 md:grid-cols-2">
            <Field field="diplome_requis" label={tf('diplome_requis')} error={errors.diplome_requis}>
              <select
                data-testid="edit-diplome-requis"
                value={String(form.diplome_requis ?? '')}
                onChange={(e) => setField('diplome_requis', e.target.value)}
                className={inputCls}
              >
                <option value="">{to('choose')}</option>
                {DIPLOME_REQUIS_OPTIONS.map((option) => (
                  <option key={option.value} value={option.value}>{option.label}</option>
                ))}
              </select>
            </Field>
            <Field
              field="annee_diplome_requis"
              label={tf('annee_diplome_requis')}
              error={errors.annee_diplome_requis}
            >
              <input
                data-testid="edit-annee-diplome-requis"
                type="number"
                inputMode="numeric"
                min={1950}
                max={new Date().getFullYear()}
                value={form.annee_diplome_requis === '' ? '' : String(form.annee_diplome_requis ?? '')}
                onChange={(e) =>
                  setField('annee_diplome_requis', e.target.value === '' ? '' : Number(e.target.value))
                }
                className={inputCls}
              />
            </Field>
          </div>

          <Field
            field="domaine_diplome_requis"
            label={tf('domaine_diplome_requis')}
            error={errors.domaine_diplome_requis}
          >
            <select
              data-testid="edit-domaine-diplome-requis"
              value={String(form.domaine_diplome_requis ?? '')}
              onChange={(e) => {
                const domaine = e.target.value;
                setField('domaine_diplome_requis', domaine);
                if (!needsSpecialiteDiplomeRequis(domaine)) {
                  setField('specialite_diplome_requis', '');
                }
              }}
              className={inputCls}
            >
              <option value="">{to('choose')}</option>
              {DOMAINE_DIPLOME_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </select>
          </Field>

          {needsSpecialiteDiplomeRequis(String(form.domaine_diplome_requis ?? '')) && (
            <Field
              field="specialite_diplome_requis"
              label={tf('specialite_diplome_requis')}
              error={errors.specialite_diplome_requis}
            >
              <input
                data-testid="edit-specialite-diplome-requis"
                type="text"
                value={String(form.specialite_diplome_requis ?? '')}
                onChange={(e) => setField('specialite_diplome_requis', e.target.value)}
                className={inputCls}
              />
            </Field>
          )}

          <Field
            field="institut_diplome_requis"
            label={tf('institut_diplome_requis')}
            error={errors.institut_diplome_requis}
          >
            <InstitutSelect
              testId="edit-institut-diplome-requis"
              universites={universites}
              value={String(form.institut_diplome_requis ?? '')}
              onChange={(v) => setField('institut_diplome_requis', v)}
              error={errors.institut_diplome_requis}
            />
          </Field>

          <AutresDiplomesEtFormations
            autresDiplomes={(form.autres_diplomes as AutreDiplomeRow[] | null) ?? []}
            formationsProfessionnelles={
              (form.formations_professionnelles as FormationProRow[] | null) ?? []
            }
            errors={errors}
            onChangeAutresDiplomes={(rows) => setField('autres_diplomes', rows)}
            onChangeFormations={(rows) => setField('formations_professionnelles', rows)}
          />
        </>
      )}
```

Dans `index.tsx`, importer la section et lui transmettre `formVersion={candidature.form_version}` là où `<SectionDiplome ... />` est déjà rendu.

- [ ] **Étape 9.6 : Adapter l'état et le diff aux tableaux**

Dans `candidature/components/DossierEditionForm/index.tsx` :

Ajouter à `buildInitialState`, après `annee_diplome` :

```ts
    diplome_requis: c.diplome_requis ?? '',
    annee_diplome_requis: c.annee_diplome_requis ?? '',
    domaine_diplome_requis: c.domaine_diplome_requis ?? '',
    specialite_diplome_requis: c.specialite_diplome_requis ?? '',
    institut_diplome_requis: c.institut_diplome_requis ?? '',
    autres_diplomes: c.autres_diplomes ?? [],
    formations_professionnelles: c.formations_professionnelles ?? [],
```

Remplacer `computeDiff` par :

```ts
  const computeDiff = useCallback((next: FormState): EditableFields => {
    const diff: EditableFields = {};
    (Object.keys(next) as EditableField[]).forEach((k) => {
      const before = lastSavedRef.current[k];
      const after = next[k];
      // Comparaison structurelle : les blocs répétables sont des tableaux, une
      // égalité de référence les considérerait toujours comme modifiés.
      if (JSON.stringify(before) === JSON.stringify(after)) {
        return;
      }
      // PUT 'null' explicite quand l'utilisateur efface un champ texte optionnel.
      diff[k] = after === '' ? null : after;
    });
    return diff;
  }, []);
```

Adapter la signature de `setField` pour accepter `EditableValue` :

```ts
  const setField = (field: EditableField, value: EditableValue): void => {
```

- [ ] **Étape 9.7 : Mettre le miroir de validation à jour**

Dans `candidature/lib/validation/submittable.ts`, ajouter avant le `return` final de `checkSubmittable` :

```ts
  // Miroir de CandidatureService::checkDiplomeRequis — n'applique les nouvelles
  // exigences qu'aux dossiers créés après la mise en production d'août 2026.
  if ((c.form_version ?? 1) >= 2) {
    const requiredV2: Array<[keyof MyCandidature, string]> = [
      ['diplome_requis', 'Le diplôme requis est obligatoire.'],
      ['annee_diplome_requis', "L'année d'obtention du diplôme requis est obligatoire."],
      ['domaine_diplome_requis', 'Le domaine du diplôme requis est obligatoire.'],
      ['institut_diplome_requis', "L'établissement de délivrance du diplôme requis est obligatoire."],
    ];

    for (const [field, message] of requiredV2) {
      const v = c[field];
      if (v === null || v === undefined || v === '') {
        missing.push(String(field));
        errors[String(field)] = message;
      }
    }

    if (c.domaine_diplome_requis === 'autres' && !c.specialite_diplome_requis) {
      missing.push('specialite_diplome_requis');
      errors.specialite_diplome_requis =
        'La spécialité du diplôme requis est obligatoire lorsque le domaine est « Autres ».';
    }

    (c.autres_diplomes ?? []).forEach((row, index) => {
      if (!row.intitule || !row.etablissement || !row.annee) {
        errors[`autres_diplomes.${index}`] =
          'Chaque diplôme complémentaire ajouté doit indiquer son intitulé, son établissement et son année.';
      }
    });

    (c.formations_professionnelles ?? []).forEach((row, index) => {
      if (!row.centre || !row.qualification || !row.annee) {
        errors[`formations_professionnelles.${index}`] =
          'Chaque formation professionnelle ajoutée doit indiquer son centre, sa qualification et son année.';
      }
    });
  }
```

- [ ] **Étape 9.8 : Étendre les récapitulatifs**

Dans `candidature/components/dossier/DossierCompleteness.tsx`, ajouter à l'ensemble `KNOWN_FIELDS` :

```ts
  'diplome_requis', 'annee_diplome_requis', 'domaine_diplome_requis',
  'specialite_diplome_requis', 'institut_diplome_requis',
  'autres_diplomes', 'formations_professionnelles',
```

Dans `candidature/components/dossier/DossierProfileSummary.tsx`, ajouter au tableau de la section diplôme, après la ligne `Année d'obtention`, en suivant la convention locale du fichier :

```ts
            ...(candidature.form_version >= 2
              ? ([
                  ['Diplôme requis', candidature.diplome_requis ?? null],
                  [
                    'Année d’obtention du diplôme requis',
                    candidature.annee_diplome_requis ? String(candidature.annee_diplome_requis) : null,
                  ],
                  ['Domaine du diplôme requis', candidature.domaine_diplome_requis ?? null],
                  ['Spécialité du diplôme requis', candidature.specialite_diplome_requis ?? null],
                  ['Établissement du diplôme requis', candidature.institut_diplome_requis ?? null],
                ] as Array<[string, string | null]>)
              : []),
```

- [ ] **Étape 9.9 : Vérifier la taille des fichiers**

```bash
cd candidature && wc -l components/DossierEditionForm/*.tsx
```

Attendu : `index.tsx` sous 800 lignes ; `primitives.tsx` et `SectionDiplome.tsx` chacun bien en deçà.

- [ ] **Étape 9.10 : Lancer les tests**

```bash
cd candidature && pnpm lint && pnpm typecheck && pnpm test tests/playwright/dossier-edition.spec.ts tests/playwright/dossier.spec.ts
```

Attendu : SUCCÈS.

- [ ] **Étape 9.11 : Commit**

```bash
git add candidature/components/DossierEditionForm/ candidature/components/dossier/ \
        candidature/lib/dossier/editableFields.ts candidature/lib/validation/submittable.ts \
        candidature/tests/playwright/dossier-edition.spec.ts
git commit -m "feat(dossier): éditer le diplôme requis sur les dossiers form_version 2"
```

---

### Tâche 10 : Vérification transverse

**Fichiers :**
- Modifier : `candidature/tests/playwright/diplome-requis.spec.ts`

- [ ] **Étape 10.1 : Ajouter le contrôle d'accessibilité**

Ajouter à `candidature/tests/playwright/diplome-requis.spec.ts` :

```ts
import AxeBuilder from '@axe-core/playwright';

test('l’étape 3 étendue ne présente aucune violation d’accessibilité', async ({ page }) => {
  await gotoStep3(page);

  await page.getByTestId('step3-domaine-diplome-requis').selectOption('autres');
  await page.getByTestId('autres-diplomes-add').click();
  await page.getByTestId('formations-pro-add').click();

  const results = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    .analyze();

  expect(results.violations).toEqual([]);
});
```

- [ ] **Étape 10.2 : Lancer le test d'accessibilité**

```bash
cd candidature && pnpm test tests/playwright/diplome-requis.spec.ts
```

Attendu : SUCCÈS, aucune violation.

- [ ] **Étape 10.3 : Lancer la suite complète du dépôt**

```bash
cd "$(git rev-parse --show-toplevel)" && make test
```

Attendu : Pest et Playwright au vert sur les quatre applications. Le test `frontend/tests/playwright/actualites-images.spec.ts` peut échouer si le backend n'est pas démarré — c'est un faux rouge connu, sans lien avec ce changement.

- [ ] **Étape 10.4 : Passage des sous-agents de revue**

Lancer en parallèle :
- `candidature-reviewer` sur le diff complet — PII, ownership, idempotence.
- `a11y-reviewer` sur `components/RepeatableRows/`, `components/diplomes/`, `components/wizard/WizardStep3Diplome.tsx`, `components/DossierEditionForm/SectionDiplome.tsx`.
- `i18n-reviewer` sur l'ensemble du diff `candidature/`.
- `security-reviewer` sur le diff `backend/`.

Traiter toute remarque CRITICAL ou HIGH avant de poursuivre.

- [ ] **Étape 10.5 : Audit Lighthouse**

```bash
cd "$(git rev-parse --show-toplevel)" && make lighthouse
```

Attendu : score ≥ 85 sur `/inscription` et `/dossier/edition`, conformément au seuil du projet pour l'app candidature.

- [ ] **Étape 10.6 : Commit final**

```bash
git add candidature/tests/playwright/diplome-requis.spec.ts
git commit -m "test(candidature): accessibilité de l'étape 3 étendue"
```

---

## Séquence de mise en production

Une fois la branche fusionnée dans `main` et la CI verte, appliquer dans cet ordre :

1. `php artisan migrate` sur le VPS — migration additive, sans verrou long.
2. Build et redémarrage du backend.
3. Build et redémarrage de l'app candidature.

L'ordre compte : le backend doit accepter les nouveaux champs avant que le front ne les envoie. Entre les deux étapes, l'ancien front reste pleinement fonctionnel puisque toutes les nouvelles colonnes sont nullables.

**Contrôle après déploiement :** ouvrir un dossier `postulant` antérieur à la mise en production, vérifier que son écran d'édition est inchangé et qu'il reste soumissible. Puis créer un dossier neuf et vérifier que les nouveaux champs sont exigés.
