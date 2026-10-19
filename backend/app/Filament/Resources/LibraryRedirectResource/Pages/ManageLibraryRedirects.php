<?php

declare(strict_types=1);

namespace App\Filament\Resources\LibraryRedirectResource\Pages;

use App\Filament\Resources\LibraryRedirectResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageLibraryRedirects extends ManageRecords
{
    protected static string $resource = LibraryRedirectResource::class;

    public function getSubheading(): string
    {
        return 'Les modifications s\'appliquent sur le site sous 10 minutes au plus.';
    }

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
