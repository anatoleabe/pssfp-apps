<?php

declare(strict_types=1);

namespace App\Filament\Resources\AffectationPointFocalResource\Pages;

use App\Filament\Resources\AffectationPointFocalResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAffectationsPointsFocaux extends ListRecords
{
    protected static string $resource = AffectationPointFocalResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Affecter un point focal')];
    }
}
