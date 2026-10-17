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

    /** Les auteurs et mots-clés sont enregistrés : l'index reflète l'état final. */
    protected function afterSave(): void
    {
        /** @var Document $document */
        $document = $this->getRecord();
        $document->syncSearchIndex();
    }
}
