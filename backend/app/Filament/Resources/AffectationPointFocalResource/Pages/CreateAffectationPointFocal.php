<?php

declare(strict_types=1);

namespace App\Filament\Resources\AffectationPointFocalResource\Pages;

use App\Filament\Resources\AffectationPointFocalResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAffectationPointFocal extends CreateRecord
{
    protected static string $resource = AffectationPointFocalResource::class;

    protected function getRedirectUrl(): string
    {
        return AffectationPointFocalResource::getUrl();
    }
}
