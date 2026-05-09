<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Translatable\HasTranslations;

/**
 * Page institutionnelle éditable via Filament (cf. spec module 1 PR K).
 *
 * Couvre /pssfp/* + pages transversales éditables (mentions, confidentialité).
 * Body en Markdown (rendu côté frontend Next.js avec sanitize). Statuts :
 * draft → in_review → published → archived.
 */
class Page extends Model
{
    use HasFactory;
    use HasTranslations;
    use HasUuids;
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'slug',
        'parent_slug',
        'title',
        'excerpt',
        'body',
        'meta_title',
        'meta_description',
        'og_image_path',
        'status',
        'published_at',
        'order',
        'is_in_menu',
        'menu_label',
        'created_by',
        'updated_by',
    ];

    public array $translatable = [
        'title',
        'excerpt',
        'body',
        'meta_title',
        'meta_description',
        'menu_label',
    ];

    protected $casts = [
        'is_in_menu' => 'boolean',
        'order' => 'integer',
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

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->where(function (Builder $q): void {
                $q->whereNull('published_at')->orWhere('published_at', '<=', now());
            });
    }

    public function scopeInMenu(Builder $query): Builder
    {
        return $query->where('is_in_menu', true)->orderBy('order');
    }
}
