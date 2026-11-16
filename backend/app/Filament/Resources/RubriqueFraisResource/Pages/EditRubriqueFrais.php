<?php

declare(strict_types=1);

namespace App\Filament\Resources\RubriqueFraisResource\Pages;

use App\Filament\Resources\RubriqueFraisResource;
use Filament\Resources\Pages\EditRecord;

class EditRubriqueFrais extends EditRecord
{
    protected static string $resource = RubriqueFraisResource::class;

    public function getSubheading(): ?string
    {
        return 'Une modification vaut pour les reçus émis ensuite : un reçu déjà émis garde sa ventilation.';
    }
}
