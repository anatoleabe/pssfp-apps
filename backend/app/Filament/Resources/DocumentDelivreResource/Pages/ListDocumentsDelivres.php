<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentDelivreResource\Pages;

use App\Filament\Resources\DocumentDelivreResource;
use Filament\Resources\Pages\ListRecords;

class ListDocumentsDelivres extends ListRecords
{
    protected static string $resource = DocumentDelivreResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
