<?php

declare(strict_types=1);

namespace App\Filament\Resources\JuryResource\Pages;

use App\Filament\Resources\JuryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateJury extends CreateRecord
{
    protected static string $resource = JuryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...$data, 'cree_par' => auth()->id()];
    }
}
