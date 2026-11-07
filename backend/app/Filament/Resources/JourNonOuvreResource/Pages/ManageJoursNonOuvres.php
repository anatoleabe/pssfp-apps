<?php

declare(strict_types=1);

namespace App\Filament\Resources\JourNonOuvreResource\Pages;

use App\Filament\Resources\JourNonOuvreResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageJoursNonOuvres extends ManageRecords
{
    protected static string $resource = JourNonOuvreResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Déclarer un jour non ouvré')];
    }
}
