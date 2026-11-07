<?php

declare(strict_types=1);

namespace App\Filament\Resources\EpreuveAnonymeResource\Pages;

use App\Filament\Resources\EpreuveAnonymeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEpreuvesAnonymes extends ListRecords
{
    protected static string $resource = EpreuveAnonymeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nouvelle épreuve anonyme')];
    }
}
