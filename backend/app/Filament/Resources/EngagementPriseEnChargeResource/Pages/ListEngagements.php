<?php

declare(strict_types=1);

namespace App\Filament\Resources\EngagementPriseEnChargeResource\Pages;

use App\Filament\Resources\EngagementPriseEnChargeResource;
use App\Filament\Resources\EngagementPriseEnChargeResource\EngagementActions;
use Filament\Resources\Pages\ListRecords;

/** Liste des engagements de prise en charge (lot A2, §9.10). */
class ListEngagements extends ListRecords
{
    protected static string $resource = EngagementPriseEnChargeResource::class;

    protected function getHeaderActions(): array
    {
        return [EngagementActions::nouvelEngagement()];
    }
}
