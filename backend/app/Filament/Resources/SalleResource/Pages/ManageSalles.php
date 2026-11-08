<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalleResource\Pages;

use App\Filament\Resources\SalleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSalles extends ManageRecords
{
    protected static string $resource = SalleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nouvelle salle')];
    }
}
