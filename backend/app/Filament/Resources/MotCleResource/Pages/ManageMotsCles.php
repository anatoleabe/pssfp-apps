<?php

declare(strict_types=1);

namespace App\Filament\Resources\MotCleResource\Pages;

use App\Filament\Resources\MotCleResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageMotsCles extends ManageRecords
{
    protected static string $resource = MotCleResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
