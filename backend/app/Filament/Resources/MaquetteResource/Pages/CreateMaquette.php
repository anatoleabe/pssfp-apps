<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaquetteResource\Pages;

use App\Filament\Resources\MaquetteResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMaquette extends CreateRecord
{
    protected static string $resource = MaquetteResource::class;

    protected function getRedirectUrl(): string
    {
        return MaquetteResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
