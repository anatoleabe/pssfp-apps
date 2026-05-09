<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Page
 */
final class PageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'parent_slug' => $this->parent_slug,
            'title' => $this->getTranslation('title', 'fr'),
            'excerpt' => $this->getTranslation('excerpt', 'fr'),
            'body' => $this->getTranslation('body', 'fr'),
            'meta_title' => $this->getTranslation('meta_title', 'fr'),
            'meta_description' => $this->getTranslation('meta_description', 'fr'),
            'og_image_path' => $this->og_image_path,
            'status' => $this->status,
            'published_at' => optional($this->published_at)->toIso8601String(),
            'order' => $this->order,
            'is_in_menu' => (bool) $this->is_in_menu,
            'menu_label' => $this->menu_label !== null ? $this->getTranslation('menu_label', 'fr') : null,
        ];
    }
}
