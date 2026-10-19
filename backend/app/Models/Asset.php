<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Spatie\Translatable\HasTranslations;

/**
 * Asset — référence à un fichier de la bibliothèque média (cf. spec sprint S5 PR V).
 *
 * Trois catégories : logo / photo / document.
 * Stockage : MinIO (disk `minio_media` public, `minio_documents` privé).
 * Variantes images : thumb (400px), medium (1200px), full (original WebP).
 */
class Asset extends Model
{
    use HasFactory;
    use HasTranslations;
    use HasUuids;

    public const CATEGORY_LOGO = 'logo';

    public const CATEGORY_PHOTO = 'photo';

    public const CATEGORY_DOCUMENT = 'document';

    public const CATEGORIES = [
        self::CATEGORY_LOGO => 'Logo',
        self::CATEGORY_PHOTO => 'Photo',
        self::CATEGORY_DOCUMENT => 'Document',
    ];

    public const PHOTO_SUBCATEGORIES = [
        'campus' => 'Campus',
        'amphis' => 'Amphithéâtres',
        'salles' => 'Salles de classe',
        'bibliotheque' => 'Bibliothèque',
        'cours' => 'Cours',
        'direction' => 'Direction',
        'enseignants' => 'Enseignants',
        'promotions' => 'Promotions',
        'soutenances' => 'Soutenances',
        'seminaires' => 'Séminaires',
        'voyages' => 'Voyages',
        'evenements' => 'Événements',
        'autres' => 'Autres',
    ];

    public const LOGO_SUBCATEGORIES = [
        'pssfp' => 'PSSFP',
        'partenaire' => 'Partenaire',
        'institution-coop' => 'Institution coopérante',
    ];

    public const DOCUMENT_SUBCATEGORIES = [
        'catalogue' => 'Catalogue',
        'convention' => 'Convention',
        'appel-candidature' => 'Appel à candidature',
        'pv-jury' => 'PV de jury',
        'autre' => 'Autre',
    ];

    /**
     * Disque de chaque catégorie : liste fermée. Le disque d'un média ne se
     * saisit jamais ; il découle de la catégorie à la création.
     */
    public const DISKS_BY_CATEGORY = [
        self::CATEGORY_LOGO => 'minio_media',
        self::CATEGORY_PHOTO => 'minio_media',
        self::CATEGORY_DOCUMENT => 'minio_documents',
    ];

    public const PUBLIC_DISK = 'minio_media';

    public const PRIVATE_DISK = 'minio_documents';

    protected $fillable = [
        'filename',
        'original_filename',
        'category',
        'subcategory',
        'mime',
        'size',
        'disk',
        'path',
        'width',
        'height',
        'alt',
        'description',
        'tags',
        'variants',
        'uploaded_by',
    ];

    public array $translatable = [
        'alt',
        'description',
    ];

    protected $casts = [
        'tags' => 'array',
        'variants' => 'array',
        'size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
    ];

    /**
     * Garde-fou : aucun enregistrement ne pose un disque hors de la liste
     * (ex. `minio_candidatures`), quel que soit le chemin du code. Seul un
     * disque modifié est contrôlé : une ligne héritée reste modifiable.
     */
    protected static function booted(): void
    {
        static::saving(function (Asset $asset): void {
            if ($asset->isDirty('disk') && ! in_array($asset->disk, self::allowedDisks(), true)) {
                throw new InvalidArgumentException("Disque de média non autorisé : {$asset->disk}.");
            }
        });
    }

    /** @return list<string> */
    public static function allowedDisks(): array
    {
        return array_values(array_unique(self::DISKS_BY_CATEGORY));
    }

    public static function diskForCategory(string $category): ?string
    {
        return self::DISKS_BY_CATEGORY[$category] ?? null;
    }

    public function hasAllowedDisk(): bool
    {
        return in_array($this->disk, self::allowedDisks(), true);
    }

    /**
     * Lien « Ouvrir » : URL publique sur le disque média, URL signée de son
     * propre chemin sur le disque privé, rien pour un disque hors liste.
     */
    public function openUrl(int $minutes = 30): ?string
    {
        return $this->disk === self::PUBLIC_DISK ? $this->publicUrl() : $this->signedUrl($minutes);
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function scopeLogos(Builder $query): Builder
    {
        return $query->where('category', self::CATEGORY_LOGO);
    }

    public function scopePhotos(Builder $query): Builder
    {
        return $query->where('category', self::CATEGORY_PHOTO);
    }

    public function scopeDocuments(Builder $query): Builder
    {
        return $query->where('category', self::CATEGORY_DOCUMENT);
    }

    public function scopeWithTag(Builder $query, string $tag): Builder
    {
        return $query->whereJsonContains('tags', $tag);
    }

    public function scopeBySubcategory(Builder $query, string $subcategory): Builder
    {
        return $query->where('subcategory', $subcategory);
    }

    /**
     * URL publique (pour disk public uniquement).
     */
    public function publicUrl(): ?string
    {
        if ($this->disk !== self::PUBLIC_DISK) {
            return null;
        }

        return Storage::disk($this->disk)->url($this->path);
    }

    /**
     * URL signée temporaire (pour fichiers privés type documents), sur le
     * seul disque privé de la médiathèque : jamais un autre bucket privé.
     */
    public function signedUrl(int $minutes = 30): ?string
    {
        if ($this->disk !== self::PRIVATE_DISK) {
            return null;
        }

        return Storage::disk($this->disk)->temporaryUrl($this->path, now()->addMinutes($minutes));
    }

    /**
     * URL d'une variante image (thumb / medium / full).
     */
    public function variantUrl(string $size = 'medium'): ?string
    {
        $path = $this->variants[$size] ?? null;

        if (! $path || ! $this->hasAllowedDisk()) {
            return $this->publicUrl();
        }

        return Storage::disk($this->disk)->url($path);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    public function isVector(): bool
    {
        return $this->mime === 'image/svg+xml';
    }
}
