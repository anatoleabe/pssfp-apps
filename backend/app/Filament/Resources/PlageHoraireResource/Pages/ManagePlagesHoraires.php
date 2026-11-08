<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlageHoraireResource\Pages;

use App\Filament\Resources\PlageHoraireResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePlagesHoraires extends ManageRecords
{
    protected static string $resource = PlageHoraireResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nouvelle plage')];
    }
}
