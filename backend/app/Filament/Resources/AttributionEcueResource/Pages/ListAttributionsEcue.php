<?php

declare(strict_types=1);

namespace App\Filament\Resources\AttributionEcueResource\Pages;

use App\Filament\Resources\AttributionEcueResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAttributionsEcue extends ListRecords
{
    protected static string $resource = AttributionEcueResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Attribuer un ECUE')];
    }
}
