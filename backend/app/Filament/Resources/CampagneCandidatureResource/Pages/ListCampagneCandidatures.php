<?php

declare(strict_types=1);

namespace App\Filament\Resources\CampagneCandidatureResource\Pages;

use App\Filament\Resources\CampagneCandidatureResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCampagneCandidatures extends ListRecords
{
    protected static string $resource = CampagneCandidatureResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->visible(fn () => auth()->user()?->can('create_campagne::candidature')),
        ];
    }
}
