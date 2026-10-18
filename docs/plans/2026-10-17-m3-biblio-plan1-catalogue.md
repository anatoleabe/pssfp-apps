# Bibliothèque V1 — Plan 1 : catalogue et administration

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Doter le backend Laravel du domaine « bibliothèque » : documents, auteurs, thésaurus, accès (prêt pour le payant), API publique en lecture, téléchargement contrôlé, recherche, traitement des PDF et administration Filament.

**Architecture:** Nouveau domaine isolé sous `App\Models` (Document, DocumentAuthor, MotCle, DocumentDownload, SearchLog, LibraryRedirect), `App\Services\Library` (accès, fichiers, recherche) et `App\Http\Controllers\Api\Library`. Une seule porte d'accès (`DocumentAccess`) lue par l'API, le téléchargement et le front. Recherche derrière une interface `DocumentSearch` (Meilisearch, repli PostgreSQL).

**Tech Stack:** Laravel 11, PHP 8.3, PostgreSQL 16, Pest, Filament 3, Scout 11 + Meilisearch, spatie/laravel-translatable, spatie/laravel-activitylog, spatie/laravel-permission, MinIO (S3), poppler-utils (`pdftoppm`).

**Spec :** `docs/specs/module-3-bibliotheque-v1-2026-10.md` (§4 accès, §5 sous-projet 1).

## Global Constraints

- Branche `feat/m3-bibliotheque-v1`, commits Conventional Commits, messages terminés par `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- `declare(strict_types=1);` en tête de chaque fichier PHP ; commentaires en français, sobres, comme le code existant.
- Jamais d'`id` interne exposé par l'API : `uuid` et `slug` uniquement.
- Textes traduisibles (`title`, `subtitle`, `abstract`, `label`) en JSONB via `HasTranslations`, langue source `fr`.
- Jamais `Storage::exists()` / `size()` / `mimeType()` sur les disques MinIO : la clé de prod n'a pas `HeadObject`. Lire avec `get()` / `readStream()` seulement.
- Permissions ajoutées par **migration additive** ; ne jamais exiger de relancer `RolePermissionSeeder` en prod.
- Tests sur PostgreSQL (`.env.testing`), lancés depuis `backend/` avec `vendor/bin/pest`. Docker dev requis : `make dev` ou `docker compose -f infra/docker/docker-compose.yml up -d postgres redis`.
- Formatage : `vendor/bin/pint --dirty` avant chaque commit.
- Pas d'extension PostgreSQL nouvelle (pas de `pg_trgm`) : le repli de recherche utilise `ILIKE`, suffisant pour ~3 000 documents.

---

## File Structure

| Fichier | Responsabilité |
|---|---|
| `backend/config/library.php` | Réglages du domaine (disques, durée URL signée, pagination, tris) |
| `backend/database/migrations/2026_10_17_100000_create_library_tables.php` | Les 7 tables du domaine |
| `backend/app/Models/Document.php` | Document, scopes, `access_mode`, Scout |
| `backend/app/Models/DocumentAuthor.php`, `MotCle.php`, `DocumentDownload.php`, `SearchLog.php`, `LibraryRedirect.php` | Modèles associés |
| `backend/database/factories/DocumentFactory.php`, `MotCleFactory.php` | Fabriques de test |
| `backend/app/Services/Library/AccessDecision.php`, `DocumentAccess.php` | Décision d'accès unique |
| `backend/app/Http/Resources/Library/DocumentResource.php` | Sérialisation publique |
| `backend/app/Http/Controllers/Api/Library/*.php` | Catalogue, fiche, téléchargement, recherche, redirections |
| `backend/app/Services/Library/Search/*.php` | `DocumentSearch` + implémentations Meilisearch et base |
| `backend/app/Services/Library/Files/*.php`, `backend/app/Jobs/ProcessDocumentFile.php` | Empreinte MD5, taille, couverture |
| `backend/app/Filament/Resources/DocumentResource*`, `MotCleResource*`, `Widgets/LibraryOverview*` | Administration |
| `backend/app/Policies/DocumentPolicy.php`, `MotClePolicy.php` | Droits |
| `backend/database/migrations/2026_10_17_110000_grant_library_permissions.php` | Permissions additives |
| `backend/tests/Feature/Library/*.php`, `backend/tests/Feature/Filament/Library*Test.php` | Tests |

---

### Task 1: Schéma et modèles du domaine bibliothèque

**Files:**
- Create: `backend/config/library.php`
- Create: `backend/database/migrations/2026_10_17_100000_create_library_tables.php`
- Create: `backend/app/Models/Document.php`, `DocumentAuthor.php`, `MotCle.php`, `DocumentDownload.php`, `SearchLog.php`, `LibraryRedirect.php`
- Create: `backend/database/factories/DocumentFactory.php`, `MotCleFactory.php`
- Test: `backend/tests/Feature/Library/DocumentModelTest.php`

**Interfaces:**
- Produces: `Document::TYPES` (array slug => libellé), `Document::ACCESS_LEVELS`, constantes `STATUS_*`, scope `published()`, accesseur `access_mode` (`libre` | `reserve` | `payant`), relations `authors()`, `motsCles()`, `downloads()` ; `DocumentFactory` avec états `published()`, `reserved()`, `paid(int $price)`, `external(string $url)`, `withFile()` ; `config('library.*')`.

- [ ] **Step 1: Écrire le test qui échoue**

`backend/tests/Feature/Library/DocumentModelTest.php` :

```php
<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\MotCle;
use Illuminate\Database\QueryException;

uses()->group('module-3', 'library');

it('crée un document publié avec auteurs ordonnés et mots-clés', function (): void {
    $doc = Document::factory()->published()->create();
    $doc->authors()->createMany([
        ['author_name' => 'Auteur Deux', 'order' => 2],
        ['author_name' => 'Auteur Un', 'order' => 1],
    ]);
    $doc->motsCles()->attach(MotCle::factory()->create());

    $fresh = $doc->fresh();
    expect($fresh->authors->pluck('author_name')->all())->toBe(['Auteur Un', 'Auteur Deux'])
        ->and($fresh->motsCles)->toHaveCount(1)
        ->and($fresh->uuid)->not->toBeEmpty();
});

it('refuse un type inconnu', function (): void {
    Document::factory()->create(['type' => 'roman']);
})->throws(QueryException::class);

it('refuse deux documents issus du même identifiant Joomla', function (): void {
    Document::factory()->create(['legacy_source' => 'docman', 'legacy_id' => 42]);
    Document::factory()->create(['legacy_source' => 'docman', 'legacy_id' => 42]);
})->throws(QueryException::class);

it('dérive le mode d\'accès affiché', function (array $attrs, string $mode): void {
    expect(Document::factory()->make($attrs)->access_mode)->toBe($mode);
})->with([
    'public' => [['access_level' => 'public', 'price_xaf' => null], 'libre'],
    'réservé' => [['access_level' => 'auditor', 'price_xaf' => null], 'reserve'],
    'payant prime sur réservé' => [['access_level' => 'auditor', 'price_xaf' => 2000], 'payant'],
]);

it('ne retient que les documents publiés à date passée', function (): void {
    Document::factory()->published()->create();
    Document::factory()->create(['status' => Document::STATUS_DRAFT]);
    Document::factory()->create(['status' => Document::STATUS_PUBLISHED, 'published_at' => now()->addDay()]);

    expect(Document::query()->published()->count())->toBe(1);
});
```

- [ ] **Step 2: Lancer le test et constater l'échec**

Run: `cd backend && vendor/bin/pest tests/Feature/Library/DocumentModelTest.php`
Expected: FAIL — `Class "App\Models\Document" not found`.

- [ ] **Step 3: Configuration du domaine**

`backend/config/library.php` :

```php
<?php

declare(strict_types=1);

/*
 * Bibliothèque virtuelle (Module 3) — cf. docs/specs/module-3-bibliotheque-v1-2026-10.md.
 */
return [
    // PDF privés ; jamais d'URL publique directe.
    'documents_disk' => env('LIBRARY_DOCUMENTS_DISK', 'minio_documents'),

    // Couvertures (images publiques).
    'covers_disk' => env('LIBRARY_COVERS_DISK', 'minio_media'),
    'covers_directory' => 'library/covers',

    'signed_url_minutes' => 30,

    'per_page' => 20,
    'max_per_page' => 50,

    // Clés de tri acceptées par l'API → colonne et sens.
    'sorts' => [
        'recent' => ['published_at', 'desc'],
        'ancien' => ['year', 'asc'],
        'titre' => ['slug', 'asc'],
        'populaire' => ['views_count', 'desc'],
    ],
];
```

- [ ] **Step 4: Migration**

`backend/database/migrations/2026_10_17_100000_create_library_tables.php` :

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Domaine bibliothèque virtuelle (Module 3 V1).
 *
 * Écarts assumés avec data-model §8 : spécialité en code texte et promotion en
 * entier (pas de tables dédiées), auteurs sans lien vers des comptes, pas de
 * colonne OCR. Ajouts de l'audit Joomla : price_xaf, external_url,
 * ouvrage_numerique, legacy_source/legacy_id.
 */
return new class extends Migration
{
    private const TYPES = "'these','memoire','article_scientifique','texte_loi','cours','acte_conference','rapport_stage','bulletin','ressource_pedagogique','ouvrage_numerique'";

    public function up(): void
    {
        Schema::create('mots_cles', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->jsonb('label');
            $table->foreignId('parent_id')->nullable()->constrained('mots_cles')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug', 160)->unique();
            $table->string('type', 30);
            $table->jsonb('title');
            $table->jsonb('subtitle')->nullable();
            $table->jsonb('abstract')->nullable();
            $table->smallInteger('year')->nullable();
            $table->string('language', 5)->default('fr');
            $table->string('specialite', 120)->nullable();
            $table->smallInteger('promotion')->nullable();
            $table->string('journal', 200)->nullable();
            $table->string('doi', 120)->nullable();
            $table->string('isbn', 30)->nullable();
            $table->integer('pages_count')->nullable();
            $table->string('file_path', 255)->nullable();
            $table->bigInteger('file_size_bytes')->nullable();
            $table->string('file_checksum_md5', 32)->nullable()->index();
            $table->string('cover_path', 255)->nullable();
            $table->string('external_url', 500)->nullable();
            $table->string('access_level', 20)->default('public');
            $table->bigInteger('price_xaf')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestampTz('published_at')->nullable();
            $table->integer('views_count')->default(0);
            $table->integer('downloads_count')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->string('legacy_source', 20)->nullable();
            $table->integer('legacy_id')->nullable();
            $table->string('legacy_url', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['legacy_source', 'legacy_id']);
            $table->index(['type', 'published_at']);
            $table->index(['specialite', 'year']);
            $table->index('promotion');
            $table->index('access_level');
            $table->index(['status', 'published_at']);
        });

        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_type_check CHECK (type IN ('.self::TYPES.'))');
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_status_check CHECK (status IN ('draft','in_review','published','archived'))");
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_access_level_check CHECK (access_level IN ('public','auditor','teacher','librarian'))");
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_language_check CHECK (language IN ('fr','en','ar','autre'))");
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_legacy_source_check CHECK (legacy_source IS NULL OR legacy_source IN ('docman','alexandria'))");
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_price_check CHECK (price_xaf IS NULL OR price_xaf > 0)');

        Schema::create('document_authors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('author_name', 200);
            $table->string('author_affiliation', 200)->nullable();
            $table->smallInteger('order')->default(1);
            $table->timestamps();
        });

        Schema::create('document_mot_cle', function (Blueprint $table): void {
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mot_cle_id')->constrained('mots_cles')->cascadeOnDelete();
            $table->primary(['document_id', 'mot_cle_id']);
        });

        Schema::create('document_downloads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->ipAddress('ip')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestampTz('downloaded_at')->useCurrent()->index();
        });

        Schema::create('search_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('query', 255);
            $table->jsonb('filters')->nullable();
            $table->integer('results_count');
            $table->ipAddress('ip')->nullable();
            $table->timestampTz('searched_at')->useCurrent()->index();
        });

        Schema::create('library_redirects', function (Blueprint $table): void {
            $table->id();
            $table->string('from_path', 500)->unique();
            $table->string('to_path', 500);
            $table->smallInteger('status_code')->default(301);
            $table->integer('hits')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_redirects');
        Schema::dropIfExists('search_logs');
        Schema::dropIfExists('document_downloads');
        Schema::dropIfExists('document_mot_cle');
        Schema::dropIfExists('document_authors');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('mots_cles');
    }
};
```

- [ ] **Step 5: Modèles**

`backend/app/Models/Document.php` :

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Translatable\HasTranslations;

/**
 * Document de la bibliothèque virtuelle (Module 3).
 *
 * Statuts : draft → in_review → published → archived.
 * L'accès effectif se décide dans App\Services\Library\DocumentAccess ;
 * `access_mode` n'est que le libellé affiché et la facette de recherche.
 */
class Document extends Model
{
    use HasFactory;
    use HasTranslations;
    use HasUuids;
    use LogsActivity;
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    public const TYPES = [
        'memoire' => 'Mémoire',
        'these' => 'Thèse',
        'ouvrage_numerique' => 'Ouvrage',
        'texte_loi' => 'Législation',
        'article_scientifique' => 'Article scientifique',
        'cours' => 'Cours',
        'acte_conference' => 'Acte de conférence',
        'rapport_stage' => 'Rapport de stage',
        'bulletin' => 'Bulletin',
        'ressource_pedagogique' => 'Ressource pédagogique',
    ];

    public const ACCESS_LEVELS = [
        'public' => 'Public',
        'auditor' => 'Auditeurs',
        'teacher' => 'Enseignants',
        'librarian' => 'Bibliothécaire',
    ];

    public const LANGUAGES = ['fr' => 'Français', 'en' => 'Anglais', 'ar' => 'Arabe', 'autre' => 'Autre'];

    protected $fillable = [
        'slug', 'type', 'title', 'subtitle', 'abstract', 'year', 'language',
        'specialite', 'promotion', 'journal', 'doi', 'isbn', 'pages_count',
        'file_path', 'file_size_bytes', 'file_checksum_md5', 'cover_path',
        'external_url', 'access_level', 'price_xaf', 'status', 'published_at',
        'views_count', 'downloads_count', 'uploaded_by', 'approved_by', 'approved_at',
        'legacy_source', 'legacy_id', 'legacy_url',
    ];

    public array $translatable = ['title', 'subtitle', 'abstract'];

    protected $casts = [
        'year' => 'integer',
        'promotion' => 'integer',
        'pages_count' => 'integer',
        'file_size_bytes' => 'integer',
        'price_xaf' => 'integer',
        'views_count' => 'integer',
        'downloads_count' => 'integer',
        'legacy_id' => 'integer',
        'published_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function authors(): HasMany
    {
        return $this->hasMany(DocumentAuthor::class)->orderBy('order');
    }

    public function motsCles(): BelongsToMany
    {
        return $this->belongsToMany(MotCle::class, 'document_mot_cle');
    }

    public function downloads(): HasMany
    {
        return $this->hasMany(DocumentDownload::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->where(function (Builder $q): void {
                $q->whereNull('published_at')->orWhere('published_at', '<=', now());
            });
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && ($this->published_at === null || $this->published_at->lte(now()));
    }

    /** Libellé d'accès : payant prime sur réservé, réservé sur libre. */
    protected function accessMode(): Attribute
    {
        return Attribute::get(fn (): string => match (true) {
            $this->price_xaf !== null => 'payant',
            $this->access_level !== 'public' => 'reserve',
            default => 'libre',
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'type', 'status', 'access_level', 'price_xaf', 'file_path', 'external_url'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
```

`backend/app/Models/DocumentAuthor.php` :

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Auteur d'un document, nom littéral (pas de lien vers un compte en V1). */
class DocumentAuthor extends Model
{
    protected $fillable = ['author_name', 'author_affiliation', 'order'];

    protected $casts = ['order' => 'integer'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
```

`backend/app/Models/MotCle.php` :

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Translatable\HasTranslations;

/** Entrée du thésaurus de la bibliothèque (hiérarchique). */
class MotCle extends Model
{
    use HasFactory;
    use HasTranslations;
    use LogsActivity;

    protected $table = 'mots_cles';

    protected $fillable = ['slug', 'label', 'parent_id'];

    public array $translatable = ['label'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'document_mot_cle');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['slug', 'label', 'parent_id'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
```

`backend/app/Models/DocumentDownload.php` :

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Journal des téléchargements (statistiques Filament). */
class DocumentDownload extends Model
{
    public $timestamps = false;

    protected $fillable = ['document_id', 'user_id', 'ip', 'user_agent', 'downloaded_at'];

    protected $casts = ['downloaded_at' => 'datetime'];
}
```

`backend/app/Models/SearchLog.php` :

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Requête de recherche publique, pour repérer les recherches sans résultat. */
class SearchLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['query', 'filters', 'results_count', 'ip', 'searched_at'];

    protected $casts = ['filters' => 'array', 'results_count' => 'integer', 'searched_at' => 'datetime'];
}
```

`backend/app/Models/LibraryRedirect.php` :

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Ancienne URL de la bibliothèque Joomla → nouveau chemin. */
class LibraryRedirect extends Model
{
    protected $fillable = ['from_path', 'to_path', 'status_code', 'hits'];

    protected $casts = ['status_code' => 'integer', 'hits' => 'integer'];
}
```

- [ ] **Step 6: Fabriques**

`backend/database/factories/DocumentFactory.php` :

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Document> */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        $title = $this->faker->sentence(6);

        return [
            'slug' => Str::slug($title).'-'.$this->faker->unique()->numerify('#####'),
            'type' => 'memoire',
            'title' => ['fr' => $title],
            'abstract' => ['fr' => $this->faker->paragraph()],
            'year' => $this->faker->numberBetween(2010, 2026),
            'language' => 'fr',
            'access_level' => 'public',
            'status' => Document::STATUS_DRAFT,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['status' => Document::STATUS_PUBLISHED, 'published_at' => now()->subDay()]);
    }

    public function reserved(): static
    {
        return $this->state(fn () => ['access_level' => 'auditor']);
    }

    public function paid(int $price = 2000): static
    {
        return $this->state(fn () => ['price_xaf' => $price]);
    }

    public function external(string $url = 'https://exemple.org/ouvrage'): static
    {
        return $this->state(fn () => ['external_url' => $url, 'file_path' => null]);
    }

    public function withFile(): static
    {
        return $this->state(fn (array $attrs) => [
            'file_path' => 'documents/'.Str::uuid().'.pdf',
            'file_size_bytes' => 120_000,
            'file_checksum_md5' => md5((string) $attrs['slug']),
        ]);
    }
}
```

`backend/database/factories/MotCleFactory.php` :

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MotCle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MotCle> */
class MotCleFactory extends Factory
{
    protected $model = MotCle::class;

    public function definition(): array
    {
        $label = $this->faker->unique()->words(2, true);

        return ['slug' => Str::slug($label), 'label' => ['fr' => $label]];
    }
}
```

- [ ] **Step 7: Lancer le test et constater le succès**

Run: `cd backend && vendor/bin/pest tests/Feature/Library/DocumentModelTest.php`
Expected: PASS (7 tests).

- [ ] **Step 8: Commit**

```bash
cd backend && vendor/bin/pint --dirty
git add config/library.php database/migrations/2026_10_17_100000_create_library_tables.php app/Models/Document.php app/Models/DocumentAuthor.php app/Models/MotCle.php app/Models/DocumentDownload.php app/Models/SearchLog.php app/Models/LibraryRedirect.php database/factories/DocumentFactory.php database/factories/MotCleFactory.php tests/Feature/Library/DocumentModelTest.php
git commit -m "feat(biblio): schema et modeles du catalogue documentaire"
```

---

### Task 2: Décision d'accès unique (`DocumentAccess`)

**Files:**
- Create: `backend/app/Services/Library/AccessDecision.php`
- Create: `backend/app/Services/Library/DocumentAccess.php`
- Test: `backend/tests/Feature/Library/DocumentAccessTest.php`

**Interfaces:**
- Consumes: `Document` (Task 1), `isPublished()`, `price_xaf`, `access_level`, `external_url`.
- Produces: `DocumentAccess::decide(?User $user, Document $document): AccessDecision` ; `AccessDecision` avec propriétés publiques `bool $allowed`, `string $reason`, constantes `PUBLIC`, `RESERVED`, `PAID`, `UNPUBLISHED`, `EXTERNAL`, méthode `toArray(): array{allowed: bool, reason: string}`.

- [ ] **Step 1: Écrire le test qui échoue**

`backend/tests/Feature/Library/DocumentAccessTest.php` :

```php
<?php

declare(strict_types=1);

use App\Models\Document;
use App\Services\Library\AccessDecision;
use App\Services\Library\DocumentAccess;

uses()->group('module-3', 'library');

it('décide l\'accès selon l\'ordre de la spec §4', function (Closure $make, bool $allowed, string $reason): void {
    $decision = app(DocumentAccess::class)->decide(null, $make());

    expect($decision->allowed)->toBe($allowed)->and($decision->reason)->toBe($reason);
})->with([
    'brouillon' => [fn () => Document::factory()->make(), false, AccessDecision::UNPUBLISHED],
    'lien externe' => [fn () => Document::factory()->published()->external()->make(), true, AccessDecision::EXTERNAL],
    'payant' => [fn () => Document::factory()->published()->withFile()->paid()->make(), false, AccessDecision::PAID],
    'payant et réservé' => [fn () => Document::factory()->published()->withFile()->paid()->reserved()->make(), false, AccessDecision::PAID],
    'réservé' => [fn () => Document::factory()->published()->withFile()->reserved()->make(), false, AccessDecision::RESERVED],
    'public' => [fn () => Document::factory()->published()->withFile()->make(), true, AccessDecision::PUBLIC],
]);

it('sérialise la décision pour l\'API', function (): void {
    $decision = app(DocumentAccess::class)->decide(null, Document::factory()->published()->withFile()->make());

    expect($decision->toArray())->toBe(['allowed' => true, 'reason' => 'public']);
});
```

- [ ] **Step 2: Lancer le test et constater l'échec**

Run: `cd backend && vendor/bin/pest tests/Feature/Library/DocumentAccessTest.php`
Expected: FAIL — `Class "App\Services\Library\DocumentAccess" not found`.

- [ ] **Step 3: Implémenter**

`backend/app/Services/Library/AccessDecision.php` :

```php
<?php

declare(strict_types=1);

namespace App\Services\Library;

/** Résultat de DocumentAccess::decide(), lu par l'API, le téléchargement et le front. */
final readonly class AccessDecision
{
    public const PUBLIC = 'public';

    public const RESERVED = 'reserved';

    public const PAID = 'paid';

    public const UNPUBLISHED = 'unpublished';

    public const EXTERNAL = 'external';

    public function __construct(public bool $allowed, public string $reason) {}

    /** @return array{allowed: bool, reason: string} */
    public function toArray(): array
    {
        return ['allowed' => $this->allowed, 'reason' => $this->reason];
    }
}
```

`backend/app/Services/Library/DocumentAccess.php` :

```php
<?php

declare(strict_types=1);

namespace App\Services\Library;

use App\Models\Document;
use App\Models\User;

/**
 * Porte d'accès unique aux fichiers de la bibliothèque (spec V1 §4).
 *
 * Points d'extension prévus, sans changer les appelants :
 * - sous-projet 4 : rôle du lecteur à l'étape « réservé » ;
 * - sous-projet 5 : droit acheté (document_entitlements) à l'étape « payant ».
 */
final class DocumentAccess
{
    public function decide(?User $user, Document $document): AccessDecision
    {
        if (! $document->isPublished()) {
            return new AccessDecision(false, AccessDecision::UNPUBLISHED);
        }

        if ($document->external_url !== null) {
            return new AccessDecision(true, AccessDecision::EXTERNAL);
        }

        if ($document->price_xaf !== null) {
            return new AccessDecision(false, AccessDecision::PAID);
        }

        if ($document->access_level !== 'public') {
            return new AccessDecision(false, AccessDecision::RESERVED);
        }

        return new AccessDecision(true, AccessDecision::PUBLIC);
    }
}
```

- [ ] **Step 4: Lancer le test et constater le succès**

Run: `cd backend && vendor/bin/pest tests/Feature/Library/DocumentAccessTest.php`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
cd backend && vendor/bin/pint --dirty
git add app/Services/Library/AccessDecision.php app/Services/Library/DocumentAccess.php tests/Feature/Library/DocumentAccessTest.php
git commit -m "feat(biblio): decision d'acces unique prete pour le payant"
```

---

### Task 3: API publique du catalogue (stats, liste, fiche, mots-clés, redirections)

**Files:**
- Create: `backend/app/Http/Resources/Library/DocumentResource.php`
- Create: `backend/app/Http/Controllers/Api/Library/CatalogueController.php`
- Create: `backend/app/Http/Controllers/Api/Library/DocumentController.php`
- Modify: `backend/routes/api.php` (ajout du groupe `library` après le groupe `articles`)
- Test: `backend/tests/Feature/Library/CatalogueApiTest.php`

**Interfaces:**
- Consumes: `Document`, `MotCle`, `LibraryRedirect`, `DocumentAccess`, `config('library.*')`.
- Produces: routes nommées `library.stats`, `library.documents.index`, `library.documents.show`, `library.mots-cles`, `library.redirects` ; `DocumentResource` (clés : `uuid`, `slug`, `type`, `type_label`, `title`, `subtitle`, `year`, `language`, `specialite`, `promotion`, `authors` (liste de noms), `mots_cles` (liste `{slug,label}`), `cover_url`, `access_mode`, `price_xaf`, `published_at`, et sur la fiche seulement : `abstract`, `journal`, `doi`, `isbn`, `pages_count`, `file_size_bytes`, `external_url`, `access`, `views_count`). Utilisé par les Tasks 4 et 5.

- [ ] **Step 1: Écrire le test qui échoue**

`backend/tests/Feature/Library/CatalogueApiTest.php` :

```php
<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\LibraryRedirect;
use App\Models\MotCle;

uses()->group('module-3', 'library');

it('compte les documents publiés par type et signale la présence de payants', function (): void {
    Document::factory()->published()->count(2)->create(['type' => 'memoire']);
    Document::factory()->published()->paid()->create(['type' => 'ouvrage_numerique']);
    Document::factory()->create(['type' => 'these']);

    $this->getJson('/v1/library/stats')
        ->assertOk()
        ->assertJsonPath('data.total', 3)
        ->assertJsonPath('data.by_type.memoire', 2)
        ->assertJsonPath('data.by_type.ouvrage_numerique', 1)
        ->assertJsonPath('data.paid_count', 1)
        ->assertJsonMissingPath('data.by_type.these');
});

it('liste les documents publiés, 20 par page, sans identifiant interne', function (): void {
    Document::factory()->published()->count(25)->create();
    Document::factory()->count(3)->create();

    $response = $this->getJson('/v1/library/documents')->assertOk();

    expect($response->json('data'))->toHaveCount(20)
        ->and($response->json('meta.total'))->toBe(25)
        ->and($response->json('data.0'))->not->toHaveKey('id')
        ->and($response->json('data.0'))->toHaveKeys(['uuid', 'slug', 'access_mode', 'cover_url']);
});

it('filtre par type, accès, mot-clé et plage d\'années', function (): void {
    $mc = MotCle::factory()->create(['slug' => 'fiscalite']);
    $cible = Document::factory()->published()->create(['type' => 'these', 'year' => 2020]);
    $cible->motsCles()->attach($mc);
    Document::factory()->published()->create(['type' => 'these', 'year' => 2010]);
    Document::factory()->published()->reserved()->create(['type' => 'these', 'year' => 2020]);
    Document::factory()->published()->create(['type' => 'memoire', 'year' => 2020]);

    $this->getJson('/v1/library/documents?type=these&acces=libre&mot_cle=fiscalite&annee_min=2015&annee_max=2025')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.uuid', $cible->uuid);
});

it('trie par popularité', function (): void {
    Document::factory()->published()->create(['slug' => 'peu-lu', 'views_count' => 3]);
    Document::factory()->published()->create(['slug' => 'tres-lu', 'views_count' => 900]);

    $this->getJson('/v1/library/documents?tri=populaire')->assertJsonPath('data.0.slug', 'tres-lu');
});

it('rejette un tri inconnu', function (): void {
    $this->getJson('/v1/library/documents?tri=hasard')->assertUnprocessable();
});

it('affiche une fiche avec la décision d\'accès et compte la consultation', function (): void {
    $doc = Document::factory()->published()->withFile()->reserved()->create(['slug' => 'support-ue4']);
    $doc->authors()->create(['author_name' => 'Auteur Test', 'order' => 1]);
    Document::factory()->published()->create(['type' => $doc->type]);

    $this->getJson('/v1/library/documents/support-ue4')
        ->assertOk()
        ->assertJsonPath('data.access.allowed', false)
        ->assertJsonPath('data.access.reason', 'reserved')
        ->assertJsonPath('data.authors.0', 'Auteur Test')
        ->assertJsonCount(1, 'related');

    expect($doc->fresh()->views_count)->toBe(1);
});

it('renvoie 404 pour une fiche non publiée', function (): void {
    Document::factory()->create(['slug' => 'brouillon']);

    $this->getJson('/v1/library/documents/brouillon')->assertNotFound();
});

it('liste le thésaurus', function (): void {
    MotCle::factory()->create(['slug' => 'tva', 'label' => ['fr' => 'TVA']]);

    $this->getJson('/v1/library/mots-cles')->assertOk()->assertJsonPath('data.0.slug', 'tva');
});

it('résout une ancienne URL Joomla et compte l\'usage', function (): void {
    $r = LibraryRedirect::create(['from_path' => '/index.php/textes-et-lois/lois', 'to_path' => '/collections/texte_loi']);

    $this->getJson('/v1/library/redirects?path=/index.php/textes-et-lois/lois')
        ->assertOk()
        ->assertJsonPath('data.to_path', '/collections/texte_loi')
        ->assertJsonPath('data.status_code', 301);

    expect($r->fresh()->hits)->toBe(1);
    $this->getJson('/v1/library/redirects?path=/inconnu')->assertNotFound();
});
```

- [ ] **Step 2: Lancer le test et constater l'échec**

Run: `cd backend && vendor/bin/pest tests/Feature/Library/CatalogueApiTest.php`
Expected: FAIL — 404 sur `/v1/library/stats`.

- [ ] **Step 3: Ressource JSON**

`backend/app/Http/Resources/Library/DocumentResource.php` :

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Library;

use App\Models\Document;
use App\Services\Library\DocumentAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin Document */
final class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $detail = $request->routeIs('library.documents.show');

        return [
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'type' => $this->type,
            'type_label' => Document::TYPES[$this->type] ?? $this->type,
            'title' => $this->getTranslation('title', 'fr'),
            'subtitle' => $this->nullableTranslation('subtitle'),
            'year' => $this->year,
            'language' => $this->language,
            'specialite' => $this->specialite,
            'promotion' => $this->promotion,
            'authors' => $this->whenLoaded('authors', fn () => $this->authors->pluck('author_name')->all(), []),
            'mots_cles' => $this->whenLoaded('motsCles', fn () => $this->motsCles
                ->map(fn ($m) => ['slug' => $m->slug, 'label' => $m->getTranslation('label', 'fr')])->all(), []),
            'cover_url' => $this->cover_path !== null
                ? Storage::disk((string) config('library.covers_disk'))->url($this->cover_path)
                : null,
            'access_mode' => $this->access_mode,
            'price_xaf' => $this->price_xaf,
            'published_at' => optional($this->published_at)->toIso8601String(),
            $this->mergeWhen($detail, fn () => [
                'abstract' => $this->nullableTranslation('abstract'),
                'journal' => $this->journal,
                'doi' => $this->doi,
                'isbn' => $this->isbn,
                'pages_count' => $this->pages_count,
                'file_size_bytes' => $this->file_size_bytes,
                'external_url' => $this->external_url,
                'views_count' => $this->views_count,
                'access' => app(DocumentAccess::class)->decide($request->user(), $this->resource)->toArray(),
            ]),
        ];
    }

    private function nullableTranslation(string $field): ?string
    {
        $value = $this->getTranslation($field, 'fr', false);

        return $value !== '' ? $value : null;
    }
}
```

- [ ] **Step 4: Contrôleurs**

`backend/app/Http/Controllers/Api/Library/CatalogueController.php` :

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Library;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\LibraryRedirect;
use App\Models\MotCle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Données transverses de la bibliothèque : compteurs, thésaurus, anciennes URL. */
final class CatalogueController extends Controller
{
    public function stats(): JsonResponse
    {
        $byType = Document::query()->published()
            ->selectRaw('type, COUNT(*) AS total')
            ->groupBy('type')
            ->pluck('total', 'type')
            ->map(fn ($n): int => (int) $n);

        return response()->json(['data' => [
            'total' => $byType->sum(),
            'by_type' => $byType,
            'paid_count' => Document::query()->published()->whereNotNull('price_xaf')->count(),
        ]]);
    }

    public function motsCles(): JsonResponse
    {
        $items = MotCle::query()->orderBy('slug')->get()->map(fn (MotCle $m): array => [
            'slug' => $m->slug,
            'label' => $m->getTranslation('label', 'fr'),
            'parent_slug' => $m->parent?->slug,
        ]);

        return response()->json(['data' => $items]);
    }

    public function redirect(Request $request): JsonResponse
    {
        $path = '/'.ltrim((string) $request->query('path', ''), '/');
        $redirect = LibraryRedirect::query()->where('from_path', $path)->first();

        if ($redirect === null) {
            return response()->json(['message' => 'Aucune redirection.'], Response::HTTP_NOT_FOUND);
        }

        $redirect->increment('hits');

        return response()->json(['data' => [
            'to_path' => $redirect->to_path,
            'status_code' => $redirect->status_code,
        ]]);
    }
}
```

`backend/app/Http/Controllers/Api/Library/DocumentController.php` :

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Library;

use App\Http\Controllers\Controller;
use App\Http\Resources\Library\DocumentResource;
use App\Models\Document;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Liste filtrable et fiche des documents publiés (spec V1 §5.3). */
final class DocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::in(array_keys(Document::TYPES))],
            'specialite' => ['nullable', 'string', 'max:120'],
            'promotion' => ['nullable', 'integer', 'min:1', 'max:99'],
            'annee_min' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'annee_max' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'langue' => ['nullable', Rule::in(array_keys(Document::LANGUAGES))],
            'acces' => ['nullable', Rule::in(['libre', 'reserve', 'payant'])],
            'mot_cle' => ['nullable', 'string', 'max:120'],
            'tri' => ['nullable', Rule::in(array_keys((array) config('library.sorts')))],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        [$column, $direction] = config('library.sorts.'.($filters['tri'] ?? 'recent'));

        $query = Document::query()->published()
            ->with(['authors', 'motsCles'])
            ->tap(fn (Builder $q) => self::applyFilters($q, $filters))
            ->orderBy($column, $direction)
            ->orderBy('id');

        $perPage = min((int) ($filters['per_page'] ?? config('library.per_page')), (int) config('library.max_per_page'));

        return DocumentResource::collection($query->paginate($perPage))->response();
    }

    public function show(string $slug): JsonResponse
    {
        $document = Document::query()->published()->with(['authors', 'motsCles'])->where('slug', $slug)->first();

        if ($document === null) {
            return response()->json(['message' => 'Document introuvable.'], Response::HTTP_NOT_FOUND);
        }

        // Compteur de consultations sans toucher updated_at ni réindexer.
        DB::table('documents')->where('id', $document->id)->increment('views_count');
        $document->views_count++;

        return DocumentResource::make($document)
            ->additional(['related' => DocumentResource::collection($this->related($document))])
            ->response();
    }

    /** @param array<string, mixed> $filters */
    public static function applyFilters(Builder $query, array $filters): void
    {
        $query
            ->when($filters['type'] ?? null, fn (Builder $q, $v) => $q->where('type', $v))
            ->when($filters['specialite'] ?? null, fn (Builder $q, $v) => $q->where('specialite', $v))
            ->when($filters['promotion'] ?? null, fn (Builder $q, $v) => $q->where('promotion', $v))
            ->when($filters['annee_min'] ?? null, fn (Builder $q, $v) => $q->where('year', '>=', $v))
            ->when($filters['annee_max'] ?? null, fn (Builder $q, $v) => $q->where('year', '<=', $v))
            ->when($filters['langue'] ?? null, fn (Builder $q, $v) => $q->where('language', $v))
            ->when($filters['mot_cle'] ?? null, fn (Builder $q, $v) => $q->whereHas('motsCles', fn (Builder $m) => $m->where('slug', $v)))
            ->when($filters['acces'] ?? null, fn (Builder $q, $v) => match ($v) {
                'payant' => $q->whereNotNull('price_xaf'),
                'reserve' => $q->whereNull('price_xaf')->where('access_level', '!=', 'public'),
                default => $q->whereNull('price_xaf')->where('access_level', 'public'),
            });
    }

    /**
     * Même type, mots-clés communs en priorité, 4 au plus.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Document>
     */
    private function related(Document $document): \Illuminate\Database\Eloquent\Collection
    {
        $motCleIds = $document->motsCles->pluck('id');

        return Document::query()->published()
            ->with(['authors', 'motsCles'])
            ->where('id', '!=', $document->id)
            ->where('type', $document->type)
            ->when($motCleIds->isNotEmpty(), fn (Builder $q) => $q->orderByRaw(
                'EXISTS (SELECT 1 FROM document_mot_cle dmc WHERE dmc.document_id = documents.id AND dmc.mot_cle_id IN ('
                .$motCleIds->map(fn ($id): int => (int) $id)->implode(',').')) DESC'
            ))
            ->orderByDesc('views_count')
            ->limit(4)
            ->get();
    }
}
```

- [ ] **Step 5: Routes**

Dans `backend/routes/api.php`, ajouter les `use` en tête :

```php
use App\Http\Controllers\Api\Library\CatalogueController;
use App\Http\Controllers\Api\Library\DocumentController as LibraryDocumentController;
```

puis, juste après le groupe `articles` :

```php
/*
 * Bibliothèque virtuelle (Module 3 V1 — docs/specs/module-3-bibliotheque-v1-2026-10.md §5.3).
 * Lecture seule et sans authentification en V1.
 */
Route::prefix('library')->name('library.')->group(function (): void {
    Route::get('/stats', [CatalogueController::class, 'stats'])->name('stats');
    Route::get('/mots-cles', [CatalogueController::class, 'motsCles'])->name('mots-cles');
    Route::get('/redirects', [CatalogueController::class, 'redirect'])->name('redirects');
    Route::get('/documents', [LibraryDocumentController::class, 'index'])->name('documents.index');
    Route::get('/documents/{slug}', [LibraryDocumentController::class, 'show'])->name('documents.show');
});
```

- [ ] **Step 6: Lancer le test et constater le succès**

Run: `cd backend && vendor/bin/pest tests/Feature/Library/CatalogueApiTest.php`
Expected: PASS (9 tests).

- [ ] **Step 7: Commit**

```bash
cd backend && vendor/bin/pint --dirty
git add app/Http/Resources/Library/DocumentResource.php app/Http/Controllers/Api/Library/CatalogueController.php app/Http/Controllers/Api/Library/DocumentController.php routes/api.php tests/Feature/Library/CatalogueApiTest.php
git commit -m "feat(biblio): API publique du catalogue (liste, fiche, stats, redirections)"
```

---

### Task 4: Téléchargement contrôlé

**Files:**
- Create: `backend/app/Http/Controllers/Api/Library/DownloadController.php`
- Modify: `backend/routes/api.php` (groupe `library`)
- Test: `backend/tests/Feature/Library/DownloadTest.php`

**Interfaces:**
- Consumes: `DocumentAccess::decide()`, `config('library.documents_disk')`, `config('library.signed_url_minutes')`.
- Produces: route `library.documents.download` → `GET /v1/library/documents/{uuid}/download` ; 302 vers URL signée (ou vers `external_url`), 403 `{reason}` si refusé, 404 si inconnu ou non publié.

- [ ] **Step 1: Écrire le test qui échoue**

`backend/tests/Feature/Library/DownloadTest.php` :

```php
<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\DocumentDownload;
use Illuminate\Support\Facades\Storage;

uses()->group('module-3', 'library');

beforeEach(function (): void {
    Storage::fake('minio_documents');
    Storage::disk('minio_documents')->buildTemporaryUrlsUsing(
        fn (string $path, DateTimeInterface $expiration): string => "https://signe.test/{$path}?exp={$expiration->getTimestamp()}"
    );
});

it('redirige vers une URL signée de 30 minutes et journalise', function (): void {
    $this->travelTo(now()->startOfMinute());
    $doc = Document::factory()->published()->withFile()->create();

    $this->get("/v1/library/documents/{$doc->uuid}/download")
        ->assertRedirect("https://signe.test/{$doc->file_path}?exp=".now()->addMinutes(30)->getTimestamp());

    expect(DocumentDownload::query()->where('document_id', $doc->id)->count())->toBe(1)
        ->and($doc->fresh()->downloads_count)->toBe(1);
});

it('refuse un document réservé ou payant avec la raison', function (Closure $make, string $reason): void {
    $doc = $make();

    $this->getJson("/v1/library/documents/{$doc->uuid}/download")
        ->assertForbidden()
        ->assertJsonPath('reason', $reason);

    expect(DocumentDownload::count())->toBe(0);
})->with([
    'réservé' => [fn () => Document::factory()->published()->withFile()->reserved()->create(), 'reserved'],
    'payant' => [fn () => Document::factory()->published()->withFile()->paid()->create(), 'paid'],
]);

it('redirige vers le lien externe', function (): void {
    $doc = Document::factory()->published()->external('https://exemple.org/livre')->create();

    $this->get("/v1/library/documents/{$doc->uuid}/download")->assertRedirect('https://exemple.org/livre');
});

it('renvoie 404 pour un brouillon, un uuid inconnu ou un document sans fichier', function (): void {
    $brouillon = Document::factory()->withFile()->create();
    $sansFichier = Document::factory()->published()->create();

    $this->getJson("/v1/library/documents/{$brouillon->uuid}/download")->assertNotFound();
    $this->getJson("/v1/library/documents/{$sansFichier->uuid}/download")->assertNotFound();
    $this->getJson('/v1/library/documents/7f1c0e9a-0000-4000-8000-000000000000/download')->assertNotFound();
});
```

- [ ] **Step 2: Lancer le test et constater l'échec**

Run: `cd backend && vendor/bin/pest tests/Feature/Library/DownloadTest.php`
Expected: FAIL — 404 sur la route de téléchargement.

- [ ] **Step 3: Implémenter**

`backend/app/Http/Controllers/Api/Library/DownloadController.php` :

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Library;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Services\Library\AccessDecision;
use App\Services\Library\DocumentAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Téléchargement contrôlé d'un document (spec V1 §5.2).
 *
 * Pas de Storage::exists() : la clé MinIO de prod n'a pas HeadObject.
 * L'existence du fichier se lit dans `file_path`.
 */
final class DownloadController extends Controller
{
    public function __invoke(Request $request, string $uuid, DocumentAccess $access): RedirectResponse|JsonResponse
    {
        $document = Str::isUuid($uuid) ? Document::query()->where('uuid', $uuid)->first() : null;

        if ($document === null || ! $document->isPublished()) {
            return $this->notFound();
        }

        $decision = $access->decide($request->user(), $document);

        if (! $decision->allowed) {
            return response()->json([
                'message' => 'Accès non disponible pour ce document.',
                'reason' => $decision->reason,
            ], Response::HTTP_FORBIDDEN);
        }

        if ($decision->reason === AccessDecision::EXTERNAL) {
            return redirect()->away((string) $document->external_url);
        }

        if ($document->file_path === null) {
            return $this->notFound();
        }

        $this->record($request, $document);

        $url = Storage::disk((string) config('library.documents_disk'))->temporaryUrl(
            $document->file_path,
            now()->addMinutes((int) config('library.signed_url_minutes')),
            ['ResponseContentDisposition' => 'inline; filename="'.$document->slug.'.pdf"'],
        );

        return redirect()->away($url);
    }

    private function record(Request $request, Document $document): void
    {
        DocumentDownload::create([
            'document_id' => $document->id,
            'user_id' => $request->user()?->id,
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            'downloaded_at' => now(),
        ]);

        DB::table('documents')->where('id', $document->id)->increment('downloads_count');
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Document introuvable.'], Response::HTTP_NOT_FOUND);
    }
}
```

Dans `backend/routes/api.php`, ajouter le `use App\Http\Controllers\Api\Library\DownloadController;` et, dans le groupe `library` :

```php
    Route::get('/documents/{uuid}/download', DownloadController::class)
        ->middleware('throttle:30,1')
        ->name('documents.download');
```

Placer cette ligne **avant** `Route::get('/documents/{slug}', ...)` pour qu'elle soit évaluée en premier.

- [ ] **Step 4: Lancer le test et constater le succès**

Run: `cd backend && vendor/bin/pest tests/Feature/Library/DownloadTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
cd backend && vendor/bin/pint --dirty
git add app/Http/Controllers/Api/Library/DownloadController.php routes/api.php tests/Feature/Library/DownloadTest.php
git commit -m "feat(biblio): telechargement controle par URL signee 30 min"
```

---

### Task 5: Recherche (Meilisearch avec repli PostgreSQL)

**Files:**
- Create: `backend/config/scout.php` (via `php artisan vendor:publish --provider="Laravel\Scout\ScoutServiceProvider"` puis modification)
- Modify: `backend/app/Models/Document.php` (trait `Searchable`)
- Create: `backend/app/Services/Library/Search/DocumentSearch.php`, `SearchResult.php`, `DatabaseDocumentSearch.php`, `MeilisearchDocumentSearch.php`, `FallbackDocumentSearch.php`
- Create: `backend/app/Http/Controllers/Api/Library/SearchController.php`
- Modify: `backend/app/Providers/AppServiceProvider.php` (liaison de `DocumentSearch`)
- Modify: `backend/routes/api.php`
- Modify: `backend/phpunit.xml` (ajout `<env name="SCOUT_DRIVER" value="null"/>`)
- Test: `backend/tests/Feature/Library/SearchApiTest.php`, `backend/tests/Unit/Services/MeilisearchFilterTest.php`

**Interfaces:**
- Consumes: `DocumentController::applyFilters()` (Task 3), `DocumentResource` (Task 3).
- Produces: `interface DocumentSearch { public function search(string $q, array $filters, int $page, int $perPage): SearchResult; }` ; `SearchResult` (`Collection $documents`, `int $total`, `array $facets` de forme `['type' => ['memoire' => 3], 'access_mode' => [...]]`, `array $highlights` uuid => titre HTML avec `<mark>`) ; `MeilisearchDocumentSearch::filterExpression(array $filters): array` ; route `library.search`.

- [ ] **Step 1: Écrire les tests qui échouent**

`backend/tests/Feature/Library/SearchApiTest.php` :

```php
<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\SearchLog;
use App\Services\Library\Search\DatabaseDocumentSearch;
use App\Services\Library\Search\DocumentSearch;
use App\Services\Library\Search\FallbackDocumentSearch;
use App\Services\Library\Search\SearchResult;

uses()->group('module-3', 'library');

it('cherche dans les titres publiés, renvoie les facettes et journalise', function (): void {
    Document::factory()->published()->create(['title' => ['fr' => 'Décentralisation fiscale au Cameroun'], 'type' => 'memoire']);
    Document::factory()->published()->paid()->create(['title' => ['fr' => 'Fiscalité locale et décentralisation'], 'type' => 'ouvrage_numerique']);
    Document::factory()->create(['title' => ['fr' => 'Décentralisation brouillon']]);

    $this->getJson('/v1/library/search?q=decentralisation')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.facets.type.memoire', 1)
        ->assertJsonPath('meta.facets.access_mode.payant', 1);

    expect(SearchLog::query()->where('query', 'decentralisation')->value('results_count'))->toBe(2);
});

it('applique les filtres du catalogue', function (): void {
    Document::factory()->published()->create(['title' => ['fr' => 'Audit interne'], 'type' => 'these']);
    Document::factory()->published()->create(['title' => ['fr' => 'Audit externe'], 'type' => 'memoire']);

    $this->getJson('/v1/library/search?q=audit&type=these')->assertJsonPath('meta.total', 1);
});

it('exige un terme de recherche', function (): void {
    $this->getJson('/v1/library/search')->assertUnprocessable();
});

it('se replie sur la base si Meilisearch est injoignable', function (): void {
    Document::factory()->published()->create(['title' => ['fr' => 'Marchés publics']]);
    $enPanne = new class implements DocumentSearch
    {
        public function search(string $q, array $filters, int $page, int $perPage): SearchResult
        {
            throw new RuntimeException('Meilisearch injoignable');
        }
    };
    app()->instance(DocumentSearch::class, new FallbackDocumentSearch($enPanne, new DatabaseDocumentSearch));

    $this->getJson('/v1/library/search?q=marches')->assertOk()->assertJsonPath('meta.total', 1);
});
```

`backend/tests/Unit/Services/MeilisearchFilterTest.php` :

```php
<?php

declare(strict_types=1);

use App\Services\Library\Search\MeilisearchDocumentSearch;

it('traduit les filtres de l\'API en expressions Meilisearch', function (): void {
    expect(MeilisearchDocumentSearch::filterExpression([
        'type' => 'these',
        'acces' => 'payant',
        'annee_min' => 2015,
        'annee_max' => 2020,
        'mot_cle' => 'tva',
        'specialite' => 'audit"; DROP',
    ]))->toBe([
        'type = "these"',
        'access_mode = "payant"',
        'year >= 2015',
        'year <= 2020',
        'mots_cles = "tva"',
        'specialite = "audit\"; DROP"',
    ]);
});
```

- [ ] **Step 2: Lancer les tests et constater l'échec**

Run: `cd backend && vendor/bin/pest tests/Feature/Library/SearchApiTest.php tests/Unit/Services/MeilisearchFilterTest.php`
Expected: FAIL — classes de recherche introuvables.

- [ ] **Step 3: Configuration Scout**

Run: `cd backend && php artisan vendor:publish --provider="Laravel\Scout\ScoutServiceProvider"`

Dans `backend/config/scout.php`, remplacer le bloc `'meilisearch' => [...]` par :

```php
    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://localhost:7700'),
        'key' => env('MEILISEARCH_KEY'),
        'index-settings' => [
            \App\Models\Document::class => [
                'searchableAttributes' => ['title', 'subtitle', 'authors', 'mots_cles_labels', 'abstract'],
                'filterableAttributes' => ['type', 'specialite', 'promotion', 'year', 'language', 'access_mode', 'mots_cles'],
                'sortableAttributes' => ['year', 'published_at', 'views_count', 'downloads_count'],
                'typoTolerance' => ['minWordSizeForTypos' => ['oneTypo' => 4, 'twoTypos' => 8]],
            ],
        ],
    ],
```

et vérifier `'queue' => env('SCOUT_QUEUE', true),`. Dans `backend/phpunit.xml`, sous `<env name="QUEUE_CONNECTION" value="sync"/>`, ajouter :

```xml
        <env name="SCOUT_DRIVER" value="null"/>
```

- [ ] **Step 4: Document indexable**

Dans `backend/app/Models/Document.php`, ajouter `use Laravel\Scout\Searchable;`, le trait `use Searchable;` et les méthodes :

```php
    public function searchableAs(): string
    {
        return 'documents';
    }

    public function shouldBeSearchable(): bool
    {
        return $this->isPublished() && ! $this->trashed();
    }

    /** @return array<string, mixed> */
    public function toSearchableArray(): array
    {
        $this->loadMissing(['authors', 'motsCles']);

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'title' => $this->getTranslation('title', 'fr'),
            'subtitle' => $this->getTranslation('subtitle', 'fr', false),
            'abstract' => $this->getTranslation('abstract', 'fr', false),
            'authors' => $this->authors->pluck('author_name')->all(),
            'mots_cles' => $this->motsCles->pluck('slug')->all(),
            'mots_cles_labels' => $this->motsCles->map(fn (MotCle $m) => $m->getTranslation('label', 'fr'))->all(),
            'type' => $this->type,
            'specialite' => $this->specialite,
            'promotion' => $this->promotion,
            'year' => $this->year,
            'language' => $this->language,
            'access_mode' => $this->access_mode,
            'published_at' => $this->published_at?->getTimestamp(),
            'views_count' => $this->views_count,
            'downloads_count' => $this->downloads_count,
        ];
    }

    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->with(['authors', 'motsCles']);
    }
```

- [ ] **Step 5: Implémentations de recherche**

`backend/app/Services/Library/Search/SearchResult.php` :

```php
<?php

declare(strict_types=1);

namespace App\Services\Library\Search;

use App\Models\Document;
use Illuminate\Support\Collection;

final readonly class SearchResult
{
    /**
     * @param  Collection<int, Document>  $documents
     * @param  array<string, array<string, int>>  $facets
     * @param  array<string, string>  $highlights  uuid => titre avec <mark>
     */
    public function __construct(
        public Collection $documents,
        public int $total,
        public array $facets = [],
        public array $highlights = [],
    ) {}
}
```

`backend/app/Services/Library/Search/DocumentSearch.php` :

```php
<?php

declare(strict_types=1);

namespace App\Services\Library\Search;

interface DocumentSearch
{
    /** @param array<string, mixed> $filters Filtres validés de l'API (cf. DocumentController::applyFilters). */
    public function search(string $q, array $filters, int $page, int $perPage): SearchResult;
}
```

`backend/app/Services/Library/Search/DatabaseDocumentSearch.php` :

```php
<?php

declare(strict_types=1);

namespace App\Services\Library\Search;

use App\Http\Controllers\Api\Library\DocumentController;
use App\Models\Document;
use Illuminate\Database\Eloquent\Builder;

/**
 * Repli PostgreSQL (ADR-0003) : ILIKE insensible aux accents sur titre,
 * résumé et auteurs. Suffisant pour quelques milliers de documents.
 */
final class DatabaseDocumentSearch implements DocumentSearch
{
    public function search(string $q, array $filters, int $page, int $perPage): SearchResult
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], self::fold($q)).'%';

        $base = Document::query()->published()
            ->where(function (Builder $w) use ($like): void {
                $w->whereRaw("translate(lower(title->>'fr'), 'àâäéèêëîïôöùûüç', 'aaaeeeeiioouuuc') LIKE ?", [$like])
                    ->orWhereRaw("translate(lower(coalesce(abstract->>'fr', '')), 'àâäéèêëîïôöùûüç', 'aaaeeeeiioouuuc') LIKE ?", [$like])
                    ->orWhereHas('authors', fn (Builder $a) => $a->whereRaw("translate(lower(author_name), 'àâäéèêëîïôöùûüç', 'aaaeeeeiioouuuc') LIKE ?", [$like]));
            });

        DocumentController::applyFilters($base, $filters);

        $facetType = (clone $base)->selectRaw('type, COUNT(*) AS n')->groupBy('type')->pluck('n', 'type')
            ->map(fn ($n): int => (int) $n)->all();
        $facetAccess = (clone $base)->selectRaw(
            "CASE WHEN price_xaf IS NOT NULL THEN 'payant' WHEN access_level <> 'public' THEN 'reserve' ELSE 'libre' END AS mode, COUNT(*) AS n"
        )->groupBy('mode')->pluck('n', 'mode')->map(fn ($n): int => (int) $n)->all();

        $total = (clone $base)->count();
        $documents = $base->with(['authors', 'motsCles'])
            ->orderByDesc('views_count')->orderBy('id')
            ->forPage($page, $perPage)->get();

        return new SearchResult($documents, $total, ['type' => $facetType, 'access_mode' => $facetAccess]);
    }

    private static function fold(string $value): string
    {
        return strtr(mb_strtolower($value), ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c']);
    }
}
```

`backend/app/Services/Library/Search/MeilisearchDocumentSearch.php` :

```php
<?php

declare(strict_types=1);

namespace App\Services\Library\Search;

use App\Models\Document;
use Meilisearch\Endpoints\Indexes;

/** Recherche principale : facettes, tolérance aux fautes, extraits surlignés. */
final class MeilisearchDocumentSearch implements DocumentSearch
{
    public function search(string $q, array $filters, int $page, int $perPage): SearchResult
    {
        $raw = Document::search($q, function (Indexes $index, string $query, array $options) use ($filters, $page, $perPage) {
            return $index->rawSearch($query, array_merge($options, [
                'filter' => self::filterExpression($filters),
                'facets' => ['type', 'access_mode'],
                'attributesToHighlight' => ['title'],
                'highlightPreTag' => '<mark>',
                'highlightPostTag' => '</mark>',
                'hitsPerPage' => $perPage,
                'page' => $page,
            ]));
        })->raw();

        $ids = array_column($raw['hits'], 'id');
        $documents = Document::query()->with(['authors', 'motsCles'])->whereIn('id', $ids)->get()
            ->sortBy(fn (Document $d): int|false => array_search($d->id, $ids, true))->values();

        $highlights = [];
        foreach ($raw['hits'] as $hit) {
            $highlights[$hit['uuid']] = $hit['_formatted']['title'] ?? $hit['title'];
        }

        return new SearchResult($documents, (int) ($raw['totalHits'] ?? 0), $raw['facetDistribution'] ?? [], $highlights);
    }

    /**
     * Filtres validés de l'API → expressions Meilisearch, dans un ordre fixe.
     * Les chaînes sont échappées (guillemets, antislash) : jamais d'injection
     * de syntaxe de filtre depuis la requête.
     *
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    public static function filterExpression(array $filters): array
    {
        $quoted = fn (string $attribute, mixed $value): string => sprintf('%s = "%s"', $attribute, addcslashes((string) $value, '"\\'));
        $present = fn (string $key): bool => isset($filters[$key]) && $filters[$key] !== '';

        return array_values(array_filter([
            $present('type') ? $quoted('type', $filters['type']) : null,
            $present('acces') ? $quoted('access_mode', $filters['acces']) : null,
            $present('annee_min') ? sprintf('year >= %d', (int) $filters['annee_min']) : null,
            $present('annee_max') ? sprintf('year <= %d', (int) $filters['annee_max']) : null,
            $present('mot_cle') ? $quoted('mots_cles', $filters['mot_cle']) : null,
            $present('specialite') ? $quoted('specialite', $filters['specialite']) : null,
            $present('promotion') ? sprintf('promotion = %d', (int) $filters['promotion']) : null,
            $present('langue') ? $quoted('language', $filters['langue']) : null,
        ]));
    }
}
```

`backend/app/Services/Library/Search/FallbackDocumentSearch.php` :

```php
<?php

declare(strict_types=1);

namespace App\Services\Library\Search;

use Illuminate\Support\Facades\Log;
use Throwable;

/** Essaie la recherche principale, bascule sur la base en cas de panne (ADR-0003). */
final readonly class FallbackDocumentSearch implements DocumentSearch
{
    public function __construct(private DocumentSearch $primary, private DocumentSearch $fallback) {}

    public function search(string $q, array $filters, int $page, int $perPage): SearchResult
    {
        try {
            return $this->primary->search($q, $filters, $page, $perPage);
        } catch (Throwable $e) {
            Log::warning('Recherche bibliothèque : repli sur PostgreSQL', ['error' => $e->getMessage()]);

            return $this->fallback->search($q, $filters, $page, $perPage);
        }
    }
}
```

- [ ] **Step 6: Liaison, contrôleur et route**

Dans `backend/app/Providers/AppServiceProvider.php`, méthode `register()` :

```php
        $this->app->bind(\App\Services\Library\Search\DocumentSearch::class, function (): \App\Services\Library\Search\DocumentSearch {
            $database = new \App\Services\Library\Search\DatabaseDocumentSearch;

            return config('scout.driver') === 'meilisearch'
                ? new \App\Services\Library\Search\FallbackDocumentSearch(new \App\Services\Library\Search\MeilisearchDocumentSearch, $database)
                : $database;
        });
```

(remplacer les noms complets par des `use` en tête de fichier, selon le style du fichier.)

`backend/app/Http/Controllers/Api/Library/SearchController.php` :

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Library;

use App\Http\Controllers\Controller;
use App\Http\Resources\Library\DocumentResource;
use App\Models\Document;
use App\Models\SearchLog;
use App\Services\Library\Search\DocumentSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/** Recherche plein texte publique (spec V1 §5.3). */
final class SearchController extends Controller
{
    public function __invoke(Request $request, DocumentSearch $search): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:200'],
            'type' => ['nullable', Rule::in(array_keys(Document::TYPES))],
            'specialite' => ['nullable', 'string', 'max:120'],
            'promotion' => ['nullable', 'integer', 'min:1', 'max:99'],
            'annee_min' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'annee_max' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'langue' => ['nullable', Rule::in(array_keys(Document::LANGUAGES))],
            'acces' => ['nullable', Rule::in(['libre', 'reserve', 'payant'])],
            'mot_cle' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $filters = Arr::except($data, ['q', 'page']);
        $page = (int) ($data['page'] ?? 1);
        $perPage = (int) config('library.per_page');

        $result = $search->search($data['q'], $filters, $page, $perPage);

        SearchLog::create([
            'query' => mb_substr($data['q'], 0, 255),
            'filters' => $filters !== [] ? $filters : null,
            'results_count' => $result->total,
            'ip' => $request->ip(),
            'searched_at' => now(),
        ]);

        return response()->json([
            'data' => DocumentResource::collection($result->documents)->resolve($request),
            'highlights' => (object) $result->highlights,
            'meta' => [
                'total' => $result->total,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => max(1, (int) ceil($result->total / $perPage)),
                'facets' => $result->facets,
            ],
        ]);
    }
}
```

Dans `backend/routes/api.php`, `use App\Http\Controllers\Api\Library\SearchController;` et dans le groupe `library` :

```php
    Route::get('/search', SearchController::class)->middleware('throttle:60,1')->name('search');
```

- [ ] **Step 7: Lancer les tests et constater le succès**

Run: `cd backend && vendor/bin/pest tests/Feature/Library tests/Unit/Services/MeilisearchFilterTest.php`
Expected: PASS (toute la suite Library).

- [ ] **Step 8: Vérification manuelle contre Meilisearch local**

Run :

```bash
cd backend && SCOUT_DRIVER=meilisearch php artisan scout:sync-index-settings
SCOUT_DRIVER=meilisearch php artisan tinker --execute='App\Models\Document::factory()->published()->create(["title" => ["fr" => "Décentralisation fiscale"]])->searchable();'
sleep 1 && curl -s "http://localhost:8000/v1/library/search?q=decentralisaton" | head -c 400
```

Expected: un résultat malgré la faute de frappe, `meta.facets.type` renseigné, `highlights` contenant `<mark>`. (Base de dev uniquement ; supprimer le document ensuite.)

- [ ] **Step 9: Commit**

```bash
cd backend && vendor/bin/pint --dirty
git add config/scout.php app/Models/Document.php app/Services/Library/Search app/Http/Controllers/Api/Library/SearchController.php app/Providers/AppServiceProvider.php routes/api.php phpunit.xml tests/Feature/Library/SearchApiTest.php tests/Unit/Services/MeilisearchFilterTest.php
git commit -m "feat(biblio): recherche Meilisearch avec facettes et repli PostgreSQL"
```

---

### Task 6: Traitement des PDF (empreinte, taille, couverture)

**Files:**
- Create: `backend/app/Services/Library/Files/CoverRenderer.php` (interface)
- Create: `backend/app/Services/Library/Files/PdftoppmCoverRenderer.php`
- Create: `backend/app/Jobs/ProcessDocumentFile.php`
- Create: `backend/app/Observers/DocumentObserver.php`
- Modify: `backend/app/Models/Document.php` (attribut `#[ObservedBy(DocumentObserver::class)]`)
- Modify: `backend/app/Providers/AppServiceProvider.php` (liaison `CoverRenderer`)
- Test: `backend/tests/Feature/Library/ProcessDocumentFileTest.php`

**Interfaces:**
- Consumes: `config('library.documents_disk')`, `config('library.covers_disk')`, `config('library.covers_directory')`.
- Produces: `interface CoverRenderer { public function render(string $pdfPath): ?string; }` (octets JPEG ou null) ; `ProcessDocumentFile::dispatch(int $documentId)` — remplit `file_size_bytes`, `file_checksum_md5`, `cover_path`. Réutilisé par la commande d'import (Plan 3). L'observateur déclenche le job quand `file_path` change.

- [ ] **Step 1: Écrire le test qui échoue**

`backend/tests/Feature/Library/ProcessDocumentFileTest.php` :

```php
<?php

declare(strict_types=1);

use App\Jobs\ProcessDocumentFile;
use App\Models\Document;
use App\Services\Library\Files\CoverRenderer;
use App\Services\Library\Files\PdftoppmCoverRenderer;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses()->group('module-3', 'library');

beforeEach(function (): void {
    Storage::fake('minio_documents');
    Storage::fake('minio_media');
});

it('calcule taille et MD5 et enregistre la couverture', function (): void {
    Storage::disk('minio_documents')->put('documents/a.pdf', '%PDF-1.4 contenu');
    $doc = Document::factory()->create();
    Document::withoutEvents(fn () => $doc->update(['file_path' => 'documents/a.pdf']));
    app()->instance(CoverRenderer::class, new class implements CoverRenderer
    {
        public function render(string $pdfPath): ?string
        {
            return file_exists($pdfPath) ? 'JPEGDATA' : null;
        }
    });

    (new ProcessDocumentFile($doc->id))->handle(app(CoverRenderer::class));

    $doc->refresh();
    expect($doc->file_checksum_md5)->toBe(md5('%PDF-1.4 contenu'))
        ->and($doc->file_size_bytes)->toBe(strlen('%PDF-1.4 contenu'))
        ->and($doc->cover_path)->toBe("library/covers/{$doc->uuid}.jpg");
    Storage::disk('minio_media')->assertExists("library/covers/{$doc->uuid}.jpg");
});

it('laisse la couverture vide si le rendu échoue', function (): void {
    Storage::disk('minio_documents')->put('documents/b.pdf', 'pas un pdf');
    $doc = Document::factory()->create();
    Document::withoutEvents(fn () => $doc->update(['file_path' => 'documents/b.pdf']));
    app()->instance(CoverRenderer::class, new class implements CoverRenderer
    {
        public function render(string $pdfPath): ?string
        {
            return null;
        }
    });

    (new ProcessDocumentFile($doc->id))->handle(app(CoverRenderer::class));

    expect($doc->fresh()->cover_path)->toBeNull()->and($doc->fresh()->file_checksum_md5)->toBe(md5('pas un pdf'));
});

it('déclenche le traitement quand le fichier change', function (): void {
    Bus::fake([ProcessDocumentFile::class]);
    $doc = Document::factory()->create();

    $doc->update(['abstract' => ['fr' => 'nouveau résumé']]);
    Bus::assertNotDispatched(ProcessDocumentFile::class);

    $doc->update(['file_path' => 'documents/c.pdf']);
    Bus::assertDispatched(ProcessDocumentFile::class, fn (ProcessDocumentFile $job) => $job->documentId === $doc->id);
});

it('rend la première page d\'un vrai PDF avec pdftoppm', function (): void {
    $pdf = tempnam(sys_get_temp_dir(), 'pdf');
    file_put_contents($pdf, Barryvdh\DomPDF\Facade\Pdf::loadHTML('<h1>Couverture</h1>')->output());

    $jpeg = (new PdftoppmCoverRenderer)->render($pdf);

    expect($jpeg)->not->toBeNull()->and(substr((string) $jpeg, 0, 3))->toBe("\xFF\xD8\xFF");
    unlink($pdf);
})->skip(fn () => trim((string) shell_exec('command -v pdftoppm')) === '', 'pdftoppm absent');
```

- [ ] **Step 2: Lancer le test et constater l'échec**

Run: `cd backend && vendor/bin/pest tests/Feature/Library/ProcessDocumentFileTest.php`
Expected: FAIL — `Class "App\Jobs\ProcessDocumentFile" not found`.

- [ ] **Step 3: Implémenter**

`backend/app/Services/Library/Files/CoverRenderer.php` :

```php
<?php

declare(strict_types=1);

namespace App\Services\Library\Files;

interface CoverRenderer
{
    /** Octets JPEG de la première page, ou null si le rendu échoue. */
    public function render(string $pdfPath): ?string;
}
```

`backend/app/Services/Library/Files/PdftoppmCoverRenderer.php` :

```php
<?php

declare(strict_types=1);

namespace App\Services\Library\Files;

use Illuminate\Support\Facades\Process;

/** Rendu de couverture via poppler-utils (`pdftoppm`), 600 px de large. */
final class PdftoppmCoverRenderer implements CoverRenderer
{
    public function render(string $pdfPath): ?string
    {
        $prefix = tempnam(sys_get_temp_dir(), 'cover');
        $result = Process::timeout(60)->run([
            'pdftoppm', '-jpeg', '-jpegopt', 'quality=82', '-f', '1', '-l', '1',
            '-scale-to-x', '600', '-scale-to-y', '-1', '-singlefile', $pdfPath, $prefix,
        ]);

        $output = $prefix.'.jpg';
        $bytes = $result->successful() && is_file($output) ? (string) file_get_contents($output) : null;

        @unlink($prefix);
        @unlink($output);

        return $bytes !== '' ? $bytes : null;
    }
}
```

`backend/app/Jobs/ProcessDocumentFile.php` :

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Document;
use App\Services\Library\Files\CoverRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Après dépôt ou import d'un PDF : taille, MD5 et couverture.
 *
 * Lecture par readStream (GetObject) uniquement — pas de HeadObject en prod.
 */
final class ProcessDocumentFile implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(public readonly int $documentId) {}

    public function handle(CoverRenderer $renderer): void
    {
        $document = Document::query()->find($this->documentId);
        if ($document === null || $document->file_path === null) {
            return;
        }

        $local = tempnam(sys_get_temp_dir(), 'doc');
        $stream = Storage::disk((string) config('library.documents_disk'))->readStream($document->file_path);
        if ($stream === null) {
            // Disque configuré avec throw=false : fichier absent ou MinIO injoignable.
            // On journalise sans faire échouer la file (l'import le signalera dans son rapport).
            @unlink($local);
            Log::warning('Bibliothèque : PDF illisible', ['document' => $document->uuid, 'path' => $document->file_path]);

            return;
        }
        $out = fopen($local, 'wb');
        stream_copy_to_stream($stream, $out);
        fclose($out);
        fclose($stream);

        $attributes = [
            'file_size_bytes' => filesize($local),
            'file_checksum_md5' => md5_file($local),
        ];

        $jpeg = $renderer->render($local);
        if ($jpeg !== null) {
            $coverPath = config('library.covers_directory')."/{$document->uuid}.jpg";
            Storage::disk((string) config('library.covers_disk'))->put($coverPath, $jpeg, ['visibility' => 'public']);
            $attributes['cover_path'] = $coverPath;
        }

        @unlink($local);

        Document::withoutEvents(fn () => $document->forceFill($attributes)->save());
        $document->searchable();
    }
}
```

`backend/app/Observers/DocumentObserver.php` :

```php
<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\ProcessDocumentFile;
use App\Models\Document;

/** Relance le traitement du PDF à chaque nouveau fichier. */
final class DocumentObserver
{
    public function saved(Document $document): void
    {
        $nouveauFichier = $document->wasRecentlyCreated || $document->wasChanged('file_path');

        if ($document->file_path !== null && $nouveauFichier) {
            ProcessDocumentFile::dispatch($document->id)->afterCommit();
        }
    }
}
```

Dans `backend/app/Models/Document.php` : `use App\Observers\DocumentObserver;`, `use Illuminate\Database\Eloquent\Attributes\ObservedBy;` et l'attribut `#[ObservedBy(DocumentObserver::class)]` juste au-dessus de `class Document extends Model`.

Dans `backend/app/Providers/AppServiceProvider.php`, `register()` :

```php
        $this->app->bind(\App\Services\Library\Files\CoverRenderer::class, \App\Services\Library\Files\PdftoppmCoverRenderer::class);
```

- [ ] **Step 4: Lancer le test et constater le succès**

Run: `cd backend && vendor/bin/pest tests/Feature/Library/ProcessDocumentFileTest.php`
Expected: PASS (4 tests ; le dernier est ignoré si `pdftoppm` est absent — il est présent en local via Homebrew).

- [ ] **Step 5: Non-régression de la suite Library**

Run: `cd backend && vendor/bin/pest tests/Feature/Library`
Expected: PASS. (Les fabriques `withFile()` déclenchent désormais le job : en test la file est `sync` et le disque n'est pas simulé partout — si `DownloadTest` échoue sur `readStream`, ajouter `Bus::fake([ProcessDocumentFile::class]);` dans son `beforeEach`.)

- [ ] **Step 6: Commit**

```bash
cd backend && vendor/bin/pint --dirty
git add app/Services/Library/Files app/Jobs/ProcessDocumentFile.php app/Observers/DocumentObserver.php app/Models/Document.php app/Providers/AppServiceProvider.php tests/Feature/Library
git commit -m "feat(biblio): empreinte, taille et couverture des PDF en file d'attente"
```

---

### Task 7: Administration Filament et permissions

**Files:**
- Modify: `backend/app/Support/ModulePermissionMap.php` (module `bibliotheque`)
- Modify: `backend/database/seeders/RolePermissionSeeder.php` (niveau `bibliotheque` pour chaque rôle)
- Create: `backend/database/migrations/2026_10_17_110000_grant_library_permissions.php`
- Create: `backend/app/Policies/DocumentPolicy.php`, `backend/app/Policies/MotClePolicy.php`
- Create: `backend/app/Filament/Resources/DocumentResource.php` + `DocumentResource/Pages/ListDocuments.php`, `CreateDocument.php`, `EditDocument.php`
- Create: `backend/app/Filament/Resources/MotCleResource.php` + `MotCleResource/Pages/ManageMotsCles.php`
- Test: `backend/tests/Feature/Filament/LibraryResourcesTest.php`

**Interfaces:**
- Consumes: `Document`, `MotCle`, `ContentPolicy` (existant, schéma `{action}_{ressource}`), `config('specialites')`.
- Produces: permissions `view_any_document`, `view_document`, `create_document`, `update_document`, `delete_document`, `view_any_mot::cle`, `view_mot::cle`, `create_mot::cle`, `update_mot::cle`, `delete_mot::cle`, `document.publish` (sensible) ; groupe de navigation « Module 3 — Bibliothèque ».

- [ ] **Step 1: Écrire le test qui échoue**

`backend/tests/Feature/Filament/LibraryResourcesTest.php` :

```php
<?php

declare(strict_types=1);

use App\Filament\Resources\DocumentResource\Pages\CreateDocument;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Jobs\ProcessDocumentFile;
use App\Models\Document;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Bus;

uses()->group('filament', 'module-3');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    Bus::fake([ProcessDocumentFile::class]);
    $this->seed([RolePermissionSeeder::class]);

    $this->librarian = User::factory()->create();
    $this->librarian->assignRole('librarian');
    $this->editor = User::factory()->create();
    $this->editor->assignRole('editor');
});

it('ouvre la liste des documents au bibliothécaire et la ferme à l\'éditeur', function (): void {
    $this->actingAs($this->librarian)->get('/admin/documents')->assertOk();
    $this->actingAs($this->editor)->get('/admin/documents')->assertForbidden();
});

it('crée un document en brouillon avec ses auteurs', function (): void {
    $this->actingAs($this->librarian);

    $this->livewire(CreateDocument::class)
        ->fillForm([
            'type' => 'memoire',
            'title.fr' => 'Budget-programme et performance',
            'slug' => 'budget-programme-et-performance',
            'language' => 'fr',
            'access_level' => 'public',
            'year' => 2024,
            'authors' => [['author_name' => 'Auteur Fictif', 'order' => 1]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $doc = Document::query()->where('slug', 'budget-programme-et-performance')->firstOrFail();
    expect($doc->status)->toBe(Document::STATUS_DRAFT)
        ->and($doc->authors->first()->author_name)->toBe('Auteur Fictif');
});

it('publie un document et trace le validateur', function (): void {
    $doc = Document::factory()->create(['status' => Document::STATUS_IN_REVIEW]);
    $this->actingAs($this->librarian);

    $this->livewire(ListDocuments::class)->callTableAction('publier', $doc);

    $doc->refresh();
    expect($doc->status)->toBe(Document::STATUS_PUBLISHED)
        ->and($doc->approved_by)->toBe($this->librarian->id)
        ->and($doc->published_at)->not->toBeNull();
});

it('publie une sélection en lot', function (): void {
    $docs = Document::factory()->count(3)->create(['status' => Document::STATUS_IN_REVIEW]);
    $this->actingAs($this->librarian);

    $this->livewire(ListDocuments::class)->callTableBulkAction('publierLot', $docs);

    expect(Document::query()->published()->count())->toBe(3);
});

it('refuse la publication à un rôle sans la permission sensible', function (): void {
    // Réglage possible depuis l'écran Rôles : retirer « Publier » au bibliothécaire.
    $this->librarian->roles->first()->revokePermissionTo('document.publish');
    app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    $doc = Document::factory()->create(['status' => Document::STATUS_IN_REVIEW]);
    $this->actingAs($this->librarian->fresh());

    $this->livewire(ListDocuments::class)->assertTableActionHidden('publier', $doc);
});
```

- [ ] **Step 2: Lancer le test et constater l'échec**

Run: `cd backend && vendor/bin/pest tests/Feature/Filament/LibraryResourcesTest.php`
Expected: FAIL — classe `DocumentResource\Pages\CreateDocument` introuvable.

- [ ] **Step 3: Permissions**

Dans `backend/app/Support/ModulePermissionMap.php`, méthode `modules()`, ajouter après `'admissions' => [...]` :

```php
            'bibliotheque' => [
                'label' => 'Module 3 — Bibliothèque',
                'description' => 'Documents de la bibliothèque virtuelle et thésaurus. La publication reste une action sensible.',
                'read' => [
                    'view_any_document', 'view_document',
                    'view_any_mot::cle', 'view_mot::cle',
                ],
                'manage' => [
                    'create_document', 'update_document', 'delete_document',
                    'create_mot::cle', 'update_mot::cle', 'delete_mot::cle',
                ],
            ],
```

et dans `sensitiveActions()` :

```php
            'document.publish' => 'Publier ou dépublier un document de la bibliothèque',
```

Dans `backend/database/seeders/RolePermissionSeeder.php`, ajouter la clé `'bibliotheque'` à chaque tableau `'modules'` : `$manage` pour `super_admin`, `admin` et `librarian`, `$none` pour tous les autres ; ajouter `'document.publish'` aux `'sensitive'` de `admin` et `librarian` (`super_admin` reçoit déjà toutes les actions sensibles).

`backend/database/migrations/2026_10_17_110000_grant_library_permissions.php` :

```php
<?php

declare(strict_types=1);

use App\Models\Role;
use App\Support\ModulePermissionMap;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permissions du Module 3, ajoutées sans relancer RolePermissionSeeder en prod
 * (le seeder resynchronise et écraserait les réglages faits depuis l'écran Rôles).
 */
return new class extends Migration
{
    private const ROLES = ['super_admin', 'admin', 'librarian'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $module = ModulePermissionMap::modules()['bibliotheque'];
        $names = [...$module['read'], ...$module['manage'], 'document.publish'];
        $permissions = collect($names)->map(fn (string $name) => Permission::findOrCreate($name, 'web'));

        Role::query()->where('guard_name', 'web')->whereIn('name', self::ROLES)->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permissions));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $module = ModulePermissionMap::modules()['bibliotheque'];
        Permission::query()->where('guard_name', 'web')
            ->whereIn('name', [...$module['read'], ...$module['manage'], 'document.publish'])
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
```

`backend/app/Policies/DocumentPolicy.php` :

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/** Droits Document (Module 3) — même schéma que les contenus du Module 1. */
final class DocumentPolicy extends ContentPolicy
{
    protected function resource(): string
    {
        return 'document';
    }

    public function publish(User $user): bool
    {
        return $user->can('document.publish');
    }
}
```

`backend/app/Policies/MotClePolicy.php` :

```php
<?php

declare(strict_types=1);

namespace App\Policies;

/** Droits du thésaurus bibliothèque. */
final class MotClePolicy extends ContentPolicy
{
    protected function resource(): string
    {
        return 'mot::cle';
    }
}
```

(Laravel découvre les policies `App\Policies\{Modèle}Policy` automatiquement ; vérifier qu'aucun `Gate::policy` explicite n'est requis dans `AppServiceProvider` en regardant comment `ArticlePolicy` est enregistrée, et suivre le même chemin.)

- [ ] **Step 4: Resource Document**

`backend/app/Filament/Resources/DocumentResource.php` :

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\DocumentResource\Pages;
use App\Models\Document;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Documents de la bibliothèque virtuelle (spec V1 §5.5).
 *
 * Circuit : brouillon → en revue → publié (validateur tracé) → archivé.
 */
class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static ?string $modelLabel = 'Document';

    protected static ?string $pluralModelLabel = 'Documents';

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationGroup = 'Module 3 — Bibliothèque';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'slug';

    public const STATUS_LABELS = [
        Document::STATUS_DRAFT => 'Brouillon',
        Document::STATUS_IN_REVIEW => 'En revue',
        Document::STATUS_PUBLISHED => 'Publié',
        Document::STATUS_ARCHIVED => 'Archivé',
    ];

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Notice')->columns(2)->schema([
                Forms\Components\Select::make('type')->label('Type')->options(Document::TYPES)->required(),
                Forms\Components\Select::make('language')->label('Langue')->options(Document::LANGUAGES)->default('fr')->required(),
                Forms\Components\TextInput::make('title.fr')->label('Titre')->required()->maxLength(300)->columnSpanFull()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get, ?string $state) => $get('slug') ? null : $set('slug', Str::limit(Str::slug((string) $state), 150, ''))),
                Forms\Components\TextInput::make('slug')->required()->unique(ignoreRecord: true)->maxLength(160)->columnSpanFull(),
                Forms\Components\TextInput::make('subtitle.fr')->label('Sous-titre')->maxLength(300)->columnSpanFull(),
                Forms\Components\Textarea::make('abstract.fr')->label('Résumé')->rows(5)->columnSpanFull(),
                Forms\Components\TextInput::make('year')->label('Année')->numeric()->minValue(1900)->maxValue(2100),
                Forms\Components\Select::make('specialite')->label('Spécialité')->options((array) config('specialites', []))->searchable(),
                Forms\Components\TextInput::make('promotion')->label('Promotion')->numeric()->minValue(1)->maxValue(99),
                Forms\Components\TextInput::make('pages_count')->label('Pages')->numeric()->minValue(1),
                Forms\Components\TextInput::make('journal')->label('Revue')->maxLength(200),
                Forms\Components\TextInput::make('doi')->label('DOI')->maxLength(120),
                Forms\Components\TextInput::make('isbn')->label('ISBN')->maxLength(30),
            ]),
            Forms\Components\Section::make('Auteurs et mots-clés')->schema([
                Forms\Components\Repeater::make('authors')->label('Auteurs')->relationship()
                    ->orderColumn('order')
                    ->schema([
                        Forms\Components\TextInput::make('author_name')->label('Nom')->required()->maxLength(200),
                        Forms\Components\TextInput::make('author_affiliation')->label('Affiliation')->maxLength(200),
                    ])->columns(2)->defaultItems(0),
                Forms\Components\Select::make('motsCles')->label('Mots-clés')->relationship('motsCles', 'slug')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->getTranslation('label', 'fr'))
                    ->multiple()->searchable()->preload(),
            ]),
            Forms\Components\Section::make('Fichier et accès')->columns(2)->schema([
                Forms\Components\FileUpload::make('file_path')->label('PDF')
                    ->disk((string) config('library.documents_disk'))->directory('documents')->visibility('private')
                    ->acceptedFileTypes(['application/pdf'])->maxSize(204800)->columnSpanFull(),
                Forms\Components\TextInput::make('external_url')->label('Ou lien externe')->url()->maxLength(500)->columnSpanFull(),
                Forms\Components\Select::make('access_level')->label('Niveau d\'accès')->options(Document::ACCESS_LEVELS)->default('public')->required(),
                Forms\Components\TextInput::make('price_xaf')->label('Prix (FCFA)')->numeric()->minValue(1)
                    ->helperText('Non vendu tant que le paiement en ligne n\'est pas actif : le document s\'affiche « Payant — bientôt disponible ».'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title.fr')->label('Titre')->searchable()->limit(60),
                Tables\Columns\TextColumn::make('type')->label('Type')->formatStateUsing(fn (string $state) => Document::TYPES[$state] ?? $state)->badge(),
                Tables\Columns\TextColumn::make('access_mode')->label('Accès')->badge()
                    ->color(fn (string $state) => match ($state) { 'payant' => 'warning', 'reserve' => 'info', default => 'success' }),
                Tables\Columns\TextColumn::make('status')->label('Statut')->badge()->formatStateUsing(fn (string $state) => self::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) { Document::STATUS_PUBLISHED => 'success', Document::STATUS_IN_REVIEW => 'warning', Document::STATUS_ARCHIVED => 'danger', default => 'gray' }),
                Tables\Columns\TextColumn::make('year')->label('Année')->sortable(),
                Tables\Columns\TextColumn::make('views_count')->label('Vues')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('downloads_count')->label('Téléch.')->sortable()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->options(Document::TYPES),
                Tables\Filters\SelectFilter::make('status')->label('Statut')->options(self::STATUS_LABELS),
                Tables\Filters\SelectFilter::make('access_level')->label('Accès')->options(Document::ACCESS_LEVELS),
                Tables\Filters\TernaryFilter::make('legacy_source')->label('Importé de l\'ancien site')->nullable(),
                Tables\Filters\TernaryFilter::make('file_path')->label('Avec fichier')->nullable(),
            ])
            ->actions([
                Tables\Actions\Action::make('soumettre')->label('Soumettre en revue')->icon('heroicon-o-paper-airplane')
                    ->visible(fn (Document $record) => $record->status === Document::STATUS_DRAFT)
                    ->action(fn (Document $record) => $record->update(['status' => Document::STATUS_IN_REVIEW])),
                Tables\Actions\Action::make('publier')->label('Publier')->icon('heroicon-o-check-badge')->color('success')
                    ->visible(fn (Document $record) => $record->status !== Document::STATUS_PUBLISHED && auth()->user()?->can('publish', $record))
                    ->requiresConfirmation()
                    ->action(fn (Document $record) => self::publish($record)),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('publierLot')->label('Publier la sélection')->icon('heroicon-o-check-badge')
                    ->visible(fn () => auth()->user()?->can('document.publish'))
                    ->requiresConfirmation()
                    ->action(fn (Collection $records) => $records->each(fn (Document $d) => self::publish($d)))
                    ->deselectRecordsAfterCompletion(),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function publish(Document $document): void
    {
        $document->update([
            'status' => Document::STATUS_PUBLISHED,
            'published_at' => $document->published_at ?? now(),
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDocuments::route('/'),
            'create' => Pages\CreateDocument::route('/create'),
            'edit' => Pages\EditDocument::route('/{record}/edit'),
        ];
    }
}
```

`backend/app/Filament/Resources/DocumentResource/Pages/ListDocuments.php` :

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
```

`backend/app/Filament/Resources/DocumentResource/Pages/CreateDocument.php` :

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Models\Document;
use Filament\Resources\Pages\CreateRecord;

class CreateDocument extends CreateRecord
{
    protected static string $resource = DocumentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...$data, 'status' => Document::STATUS_DRAFT, 'uploaded_by' => auth()->id()];
    }
}
```

`backend/app/Filament/Resources/DocumentResource/Pages/EditDocument.php` :

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDocument extends EditRecord
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
```

- [ ] **Step 5: Resource du thésaurus**

`backend/app/Filament/Resources/MotCleResource.php` :

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\MotCleResource\Pages;
use App\Models\MotCle;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Thésaurus de la bibliothèque (mots-clés hiérarchiques). */
class MotCleResource extends Resource
{
    protected static ?string $model = MotCle::class;

    protected static ?string $modelLabel = 'Mot-clé';

    protected static ?string $pluralModelLabel = 'Thésaurus';

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Module 3 — Bibliothèque';

    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('label.fr')->label('Libellé')->required()->maxLength(120),
            Forms\Components\TextInput::make('slug')->required()->unique(ignoreRecord: true)->maxLength(120),
            Forms\Components\Select::make('parent_id')->label('Terme parent')->relationship('parent', 'slug')->searchable()->preload(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('label.fr')->label('Libellé')->searchable(),
                Tables\Columns\TextColumn::make('parent.slug')->label('Parent'),
                Tables\Columns\TextColumn::make('documents_count')->counts('documents')->label('Documents')->sortable(),
            ])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->defaultSort('slug');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageMotsCles::route('/')];
    }
}
```

`backend/app/Filament/Resources/MotCleResource/Pages/ManageMotsCles.php` :

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\MotCleResource\Pages;

use App\Filament\Resources\MotCleResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageMotsCles extends ManageRecords
{
    protected static string $resource = MotCleResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
```

- [ ] **Step 6: Lancer les tests et constater le succès**

Run: `cd backend && vendor/bin/pest tests/Feature/Filament/LibraryResourcesTest.php tests/Feature/Filament/RoleResourceTest.php tests/Feature/Filament/PanelAccessTest.php`
Expected: PASS. Si `RoleResourceTest` compte les modules ou permissions, mettre à jour l'attendu (4 modules au lieu de 3) — c'est un changement voulu.

- [ ] **Step 7: Commit**

```bash
cd backend && vendor/bin/pint --dirty
git add app/Support/ModulePermissionMap.php database/seeders/RolePermissionSeeder.php database/migrations/2026_10_17_110000_grant_library_permissions.php app/Policies/DocumentPolicy.php app/Policies/MotClePolicy.php app/Filament/Resources/DocumentResource.php app/Filament/Resources/DocumentResource app/Filament/Resources/MotCleResource.php app/Filament/Resources/MotCleResource tests/Feature/Filament
git commit -m "feat(biblio): administration Filament des documents et du thesaurus"
```

---

### Task 8: Tableau de bord, indexation planifiée, documentation et vérification finale

**Files:**
- Create: `backend/app/Filament/Widgets/LibraryOverview.php`
- Create: `backend/app/Filament/Widgets/LibraryZeroResultSearches.php`
- Modify: `backend/routes/console.php` (planification)
- Modify: `docs/api-contract.md` (§8 : renvoi vers la spec V1 et liste des routes réellement livrées)
- Modify: `.env.example` (variables `LIBRARY_DOCUMENTS_DISK`, `LIBRARY_COVERS_DISK` commentées)
- Test: `backend/tests/Feature/Filament/LibraryWidgetsTest.php`

**Interfaces:**
- Consumes: `Document`, `DocumentDownload`, `SearchLog`.
- Produces: widgets visibles des rôles ayant `view_any_document`.

- [ ] **Step 1: Écrire le test qui échoue**

`backend/tests/Feature/Filament/LibraryWidgetsTest.php` :

```php
<?php

declare(strict_types=1);

use App\Filament\Widgets\LibraryOverview;
use App\Filament\Widgets\LibraryZeroResultSearches;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\SearchLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

uses()->group('filament', 'module-3');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([RolePermissionSeeder::class]);
    $this->librarian = User::factory()->create();
    $this->librarian->assignRole('librarian');
});

it('affiche les compteurs de la bibliothèque', function (): void {
    $doc = Document::factory()->published()->create();
    Document::factory()->create();
    DocumentDownload::create(['document_id' => $doc->id, 'downloaded_at' => now()->subDays(3)]);
    DocumentDownload::create(['document_id' => $doc->id, 'downloaded_at' => now()->subDays(40)]);

    $this->actingAs($this->librarian);
    $this->livewire(LibraryOverview::class)
        ->assertSee('Documents publiés')->assertSee('1')
        ->assertSee('Téléchargements (30 j)');
});

it('classe les recherches sans résultat les plus fréquentes', function (): void {
    foreach (['loi de finances 2027', 'loi de finances 2027', 'cadastre'] as $q) {
        SearchLog::create(['query' => $q, 'results_count' => 0, 'searched_at' => now()->subDay()]);
    }
    SearchLog::create(['query' => 'tva', 'results_count' => 12, 'searched_at' => now()->subDay()]);

    $this->actingAs($this->librarian);
    $this->livewire(LibraryZeroResultSearches::class)
        ->assertSee('loi de finances 2027')->assertSee('cadastre')->assertDontSee('tva');
});

it('masque les widgets aux rôles sans accès bibliothèque', function (): void {
    $editor = User::factory()->create();
    $editor->assignRole('editor');
    $this->actingAs($editor);

    expect(LibraryOverview::canView())->toBeFalse()->and(LibraryZeroResultSearches::canView())->toBeFalse();
});
```

- [ ] **Step 2: Lancer le test et constater l'échec**

Run: `cd backend && vendor/bin/pest tests/Feature/Filament/LibraryWidgetsTest.php`
Expected: FAIL — widgets introuvables.

- [ ] **Step 3: Implémenter les widgets**

`backend/app/Filament/Widgets/LibraryOverview.php` :

```php
<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Document;
use App\Models\DocumentDownload;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Compteurs de la bibliothèque pour le tableau de bord (spec V1 §5.5). */
class LibraryOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 40;

    public static function canView(): bool
    {
        return auth()->user()?->can('view_any_document') ?? false;
    }

    protected function getStats(): array
    {
        return [
            Stat::make('Documents publiés', Document::query()->published()->count()),
            Stat::make('En attente de validation', Document::query()->where('status', Document::STATUS_IN_REVIEW)->count()),
            Stat::make('Téléchargements (30 j)', DocumentDownload::query()->where('downloaded_at', '>=', now()->subDays(30))->count()),
        ];
    }
}
```

`backend/app/Filament/Widgets/LibraryZeroResultSearches.php` :

```php
<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\SearchLog;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Les 10 recherches sans résultat les plus fréquentes sur 30 jours : pistes d'enrichissement du fonds. */
class LibraryZeroResultSearches extends TableWidget
{
    protected static ?int $sort = 41;

    protected static ?string $heading = 'Recherches sans résultat (30 jours)';

    public static function canView(): bool
    {
        return auth()->user()?->can('view_any_document') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                SearchLog::query()
                    ->selectRaw('MIN(id) AS id, query, COUNT(*) AS total')
                    ->where('results_count', 0)
                    ->where('searched_at', '>=', now()->subDays(30))
                    ->groupBy('query')
                    ->orderByDesc('total')
                    ->limit(10)
            )
            ->columns([
                Tables\Columns\TextColumn::make('query')->label('Recherche'),
                Tables\Columns\TextColumn::make('total')->label('Occurrences'),
            ])
            ->paginated(false);
    }
}
```

- [ ] **Step 4: Indexation planifiée**

Dans `backend/routes/console.php`, ajouter `use Illuminate\Support\Facades\Schedule;` puis :

```php
/*
 * Bibliothèque (ADR-0003) : réindexation hebdomadaire complète, filet de
 * sécurité si une indexation en file a échoué. Sans effet hors Meilisearch.
 */
Schedule::command('scout:import', ['App\\Models\\Document'])
    ->weeklyOn(0, '03:30')
    ->when(fn (): bool => config('scout.driver') === 'meilisearch')
    ->withoutOverlapping();
```

Vérifier : `cd backend && php artisan schedule:list | grep scout` → une ligne `scout:import`.

- [ ] **Step 5: Documentation**

Dans `docs/api-contract.md`, en tête de la section 8, insérer :

```markdown
> **V1 livrée (octobre 2026)** — cf. `docs/specs/module-3-bibliotheque-v1-2026-10.md` §5.3. Routes réellement exposées : `GET /v1/library/stats`, `/library/documents` (filtres `type`, `specialite`, `promotion`, `annee_min`, `annee_max`, `langue`, `acces`, `mot_cle`, `tri`), `/library/documents/{slug}` (avec `access` et `related`), `/library/documents/{uuid}/download`, `/library/search?q=`, `/library/mots-cles`, `/library/redirects?path=`. Lecture seule, sans authentification ; favoris, citations et capacité `library:read:restricted` arrivent avec le sous-projet 4.
```

Dans `.env.example`, sous les variables MinIO :

```bash
# Bibliothèque (Module 3) — disques des PDF privés et des couvertures publiques
# LIBRARY_DOCUMENTS_DISK=minio_documents
# LIBRARY_COVERS_DISK=minio_media
```

- [ ] **Step 6: Suite complète et formatage**

Run: `cd backend && vendor/bin/pint --test && vendor/bin/pest`
Expected: Pint sans écart ; toute la suite verte (les tests existants ne doivent pas régresser).

- [ ] **Step 7: Revue par les sous-agents**

Lancer en parallèle `biblio-reviewer`, `security-reviewer` et `filament-reviewer` sur `git diff main...HEAD -- backend`. Corriger les points CRITICAL et HIGH avant de conclure.

- [ ] **Step 8: Commit**

```bash
git add backend/app/Filament/Widgets/LibraryOverview.php backend/app/Filament/Widgets/LibraryZeroResultSearches.php backend/routes/console.php backend/tests/Feature/Filament/LibraryWidgetsTest.php docs/api-contract.md .env.example
git commit -m "feat(biblio): tableau de bord, reindexation planifiee et documentation API"
```

---

## Hors de ce plan (plans suivants)

- **Plan 2** — application `/library` (accueil A+B+C, collections, recherche, fiche, middleware de redirection, SEO, Playwright, Lighthouse).
- **Plan 3** — export du dump Joomla, rsync des PDF, `config/biblio_migration.php`, commande `biblio:import`, redirections, bascule DNS/nginx/pm2, installation Meilisearch et `poppler-utils` sur le VPS.
