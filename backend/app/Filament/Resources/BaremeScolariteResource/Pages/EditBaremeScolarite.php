<?php

declare(strict_types=1);

namespace App\Filament\Resources\BaremeScolariteResource\Pages;

use App\Filament\Resources\BaremeScolariteResource;
use Filament\Resources\Pages\EditRecord;

class EditBaremeScolarite extends EditRecord
{
    protected static string $resource = BaremeScolariteResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
