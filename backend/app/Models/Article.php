<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Translatable\HasTranslations;

/**
 * Article — actualité, billet ou communiqué (cf. spec module 1 PR N).
 *
 * Statuts : draft → in_review → published → archived.
 * Catégorie en TEXT (pas de table dédiée en V1).
 */
class Article extends Model
{
    use HasFactory;
    use HasTranslations;
    use HasUuids;
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    public const CATEGORIES = [
        'evenement' => 'Événement',
        'cooperation' => 'Coopération',
        'vie-academique' => 'Vie académique',
        'communique' => 'Communiqué',
        'partenariat' => 'Partenariat',
    ];

    protected $fillable = [
        'slug',
        'title',
        'excerpt',
        'body',
        'featured_image_path',
        'category',
        'status',
        'published_at',
        'is_pinned',
        'views_count',
        'author_id',
    ];

    public array $translatable = [
        'title',
        'excerpt',
        'body',
    ];

    protected $casts = [
        'is_pinned' => 'boolean',
        'views_count' => 'integer',
        'published_at' => 'datetime',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->where(function (Builder $q): void {
                $q->whereNull('published_at')->orWhere('published_at', '<=', now());
            });
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_pinned', true)->orderByDesc('published_at');
    }
}
