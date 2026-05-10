<?php

declare(strict_types=1);

namespace App\Filament\Resources\AssetResource\Pages;

use App\Filament\Resources\AssetResource;
use App\Services\AssetImportService;
use Filament\Resources\Pages\CreateRecord;

class CreateAsset extends CreateRecord
{
    protected static string $resource = AssetResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['disk'])) {
            $data['disk'] = ($data['category'] ?? null) === 'document'
                ? AssetImportService::DOCUMENTS_DISK
                : AssetImportService::MEDIA_DISK;
        }
        if (auth()->id() !== null) {
            $data['uploaded_by'] = auth()->id();
        }

        return $data;
    }
}
