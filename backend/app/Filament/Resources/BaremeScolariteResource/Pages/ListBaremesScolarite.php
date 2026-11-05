<?php

declare(strict_types=1);

namespace App\Filament\Resources\BaremeScolariteResource\Pages;

use App\Filament\Resources\BaremeScolariteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBaremesScolarite extends ListRecords
{
    protected static string $resource = BaremeScolariteResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
