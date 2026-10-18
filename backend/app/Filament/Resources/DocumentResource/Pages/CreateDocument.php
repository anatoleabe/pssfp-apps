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

    protected function afterCreate(): void
    {
        /** @var Document $document */
        $document = $this->getRecord();
        $document->syncSearchIndex();
    }
}
