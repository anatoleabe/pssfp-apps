<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\DocumentObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Laravel\Scout\Searchable;
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
#[ObservedBy(DocumentObserver::class)]
class Document extends Model
{
    use HasFactory;
    use HasTranslations;
    use HasUuids;
    use LogsActivity;
    use Searchable;
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
        'rapport' => 'Rapport et publication',
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

    /**
     * Anciennes adresses (site Joomla) qui mènent à la fiche publique.
     * Clé calculée (`site_path`) : lecture seule, l'écriture passe par
     * l'écran des redirections.
     *
     * Attention : la clé locale n'est pas une colonne. Chargement paresseux
     * ou `load()` seulement — jamais `whereHas()`, `has()`, `withCount()` ni
     * jointure sur cette relation (SQL invalide sur `documents.site_path`).
     */
    public function legacyRedirects(): HasMany
    {
        return $this->hasMany(LibraryRedirect::class, 'to_path', 'site_path');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Compte qui a déposé le document (contributeur, bibliothécaire ou import). */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
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

    /**
     * Extension du fichier stocké (minuscules), « pdf » à défaut ; null sans fichier.
     * Des documents importés de Joomla sont des présentations ou des textes Word.
     */
    public function fileExtension(): ?string
    {
        if ($this->file_path === null) {
            return null;
        }
        $extension = strtolower(pathinfo($this->file_path, PATHINFO_EXTENSION));

        return preg_match('/^[a-z0-9]{1,5}$/', $extension) === 1 ? $extension : 'pdf';
    }

    /** Chemin de la fiche sur le site de la bibliothèque (cible des redirections). */
    protected function sitePath(): Attribute
    {
        return Attribute::get(fn (): string => '/documents/'.$this->slug);
    }

    /**
     * Dossier des couvertures déposées à la main : le traitement automatique
     * du PDF ne les remplace plus, sauf régénération forcée.
     */
    public static function customCoversDirectory(): string
    {
        return (string) config('library.covers_directory').'/custom';
    }

    /**
     * URL publique de la couverture, versionnée : une couverture régénérée
     * ou remplacée garde parfois le même chemin, le paramètre `v` (date de
     * dernière écriture de la fiche) force navigateurs et cache d'images à
     * la recharger. Pas d'appel au stockage (aucun HeadObject).
     */
    public function coverUrl(): ?string
    {
        if ($this->cover_path === null) {
            return null;
        }

        $url = Storage::disk((string) config('library.covers_disk'))->url($this->cover_path);

        return $url.(str_contains($url, '?') ? '&' : '?').'v='.($this->updated_at?->getTimestamp() ?? 0);
    }

    public function hasCustomCover(): bool
    {
        return $this->cover_path !== null && str_starts_with($this->cover_path, self::customCoversDirectory().'/');
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
            ->logOnly(['title', 'type', 'status', 'access_level', 'price_xaf', 'file_path', 'external_url', 'cover_path'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function searchableAs(): string
    {
        return config('scout.prefix').'documents';
    }

    public function shouldBeSearchable(): bool
    {
        return $this->isPublished() && ! $this->trashed();
    }

    /**
     * Met l'index en phase après l'enregistrement des relations (auteurs,
     * mots-clés), que Filament sauvegarde après le document lui-même à la création.
     */
    public function syncSearchIndex(): void
    {
        $this->load(['authors', 'motsCles']);

        $this->shouldBeSearchable() ? $this->searchable() : $this->unsearchable();
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
}
