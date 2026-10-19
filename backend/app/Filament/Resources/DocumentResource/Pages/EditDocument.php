<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Models\Document;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDocument extends EditRecord
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    /**
     * Un contributeur ne change ni l'accès ni le prix, même par une requête forgée.
     * (Filament revérifie `update` à chaque requête : un brouillon soumis en
     * revue entre-temps lui est fermé.)
     *
     * @param  array<string, mixed>  $data
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return DocumentResource::enforceContributorUpdateData($data);
    }

    /** Les auteurs et mots-clés sont enregistrés : l'index reflète l'état final. */
    protected function afterSave(): void
    {
        /** @var Document $document */
        $document = $this->getRecord();
        $document->syncSearchIndex();
    }
}
