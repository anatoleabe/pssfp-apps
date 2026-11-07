<?php

declare(strict_types=1);

namespace App\Filament\Resources\JuryResource\Pages;

use App\Filament\Resources\JuryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListJurys extends ListRecords
{
    protected static string $resource = JuryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Désigner un jury')];
    }
}
