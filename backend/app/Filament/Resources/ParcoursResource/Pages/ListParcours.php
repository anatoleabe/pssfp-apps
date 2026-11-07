<?php

declare(strict_types=1);

namespace App\Filament\Resources\ParcoursResource\Pages;

use App\Filament\Resources\ParcoursResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListParcours extends ListRecords
{
    protected static string $resource = ParcoursResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
