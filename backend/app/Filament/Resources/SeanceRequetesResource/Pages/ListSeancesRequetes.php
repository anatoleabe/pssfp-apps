<?php

declare(strict_types=1);

namespace App\Filament\Resources\SeanceRequetesResource\Pages;

use App\Filament\Resources\SeanceRequetesResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSeancesRequetes extends ListRecords
{
    protected static string $resource = SeanceRequetesResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nouvelle séance')];
    }
}
