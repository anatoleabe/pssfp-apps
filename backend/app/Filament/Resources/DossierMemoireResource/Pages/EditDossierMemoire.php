<?php

declare(strict_types=1);

namespace App\Filament\Resources\DossierMemoireResource\Pages;

use App\Filament\Resources\DossierMemoireResource;
use Filament\Resources\Pages\EditRecord;

class EditDossierMemoire extends EditRecord
{
    protected static string $resource = DossierMemoireResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
