<?php

declare(strict_types=1);

namespace App\Filament\Resources\SeanceResource\Pages;

use App\Filament\Resources\SeanceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSeances extends ListRecords
{
    protected static string $resource = SeanceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Planifier une séance')];
    }
}
