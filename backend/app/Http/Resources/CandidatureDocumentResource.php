<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CandidatureDocument;
use App\Services\DocumentUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CandidatureDocument
 */
final class CandidatureDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'type' => $this->type,
            'original_filename' => $this->original_filename,
            'size' => $this->size,
            'url' => app(DocumentUploadService::class)->signedUrl($this->resource),
            'uploaded_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
