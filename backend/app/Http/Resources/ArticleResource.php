<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Article;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Article
 */
final class ArticleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $excerpt = $this->getTranslation('excerpt', 'fr');
        $body = $this->getTranslation('body', 'fr');

        return [
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'title' => $this->getTranslation('title', 'fr'),
            'excerpt' => $excerpt !== '' ? $excerpt : null,
            'body' => $request->routeIs('articles.show') ? ($body !== '' ? $body : null) : null,
            'featured_image_path' => $this->featured_image_path,
            'category' => $this->category,
            'category_label' => $this->category !== null
                ? (Article::CATEGORIES[$this->category] ?? $this->category)
                : null,
            'is_pinned' => (bool) $this->is_pinned,
            'published_at' => optional($this->published_at)->toIso8601String(),
        ];
    }
}
