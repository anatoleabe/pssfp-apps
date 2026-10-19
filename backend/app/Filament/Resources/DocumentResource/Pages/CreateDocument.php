<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Models\Document;
use Filament\Resources\Pages\CreateRecord;

class CreateDocument extends CreateRecord
{
    protected static string $resource = DocumentResource::class;

    /** Tout dépôt naît brouillon, au nom de son auteur ; un contributeur ne fixe ni accès ni prix. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return DocumentResource::enforceContributorCreateData(
            [...$data, 'status' => Document::STATUS_DRAFT, 'uploaded_by' => auth()->id()],
        );
    }

    protected function afterCreate(): void
    {
        /** @var Document $document */
        $document = $this->getRecord();
        $document->syncSearchIndex();
    }
}
