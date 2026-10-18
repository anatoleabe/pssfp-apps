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
    private bool $detailed = false;

    /** Active les champs réservés à la fiche (opt-in explicite). */
    public function detailed(): static
    {
        $this->detailed = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $detail = $this->detailed;

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
