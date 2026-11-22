<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaClasseResource\Pages;

use App\Filament\Resources\MaClasseResource;
use Filament\Resources\Pages\ListRecords;

class ListMaClasse extends ListRecords
{
    protected static string $resource = MaClasseResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
