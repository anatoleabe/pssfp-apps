<?php

declare(strict_types=1);

namespace App\Filament\Resources\LibraryRedirectResource\Pages;

use App\Filament\Resources\LibraryRedirectResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageLibraryRedirects extends ManageRecords
{
    protected static string $resource = LibraryRedirectResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
