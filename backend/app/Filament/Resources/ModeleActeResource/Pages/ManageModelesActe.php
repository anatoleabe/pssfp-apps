<?php

declare(strict_types=1);

namespace App\Filament\Resources\ModeleActeResource\Pages;

use App\Filament\Resources\ModeleActeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageModelesActe extends ManageRecords
{
    protected static string $resource = ModeleActeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nouveau modèle')];
    }
}
