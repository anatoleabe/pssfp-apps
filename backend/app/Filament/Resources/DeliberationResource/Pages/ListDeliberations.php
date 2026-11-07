<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliberationResource\Pages;

use App\Filament\Resources\DeliberationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDeliberations extends ListRecords
{
    protected static string $resource = DeliberationResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nouvelle délibération')];
    }
}
