<?php

declare(strict_types=1);

namespace App\Filament\Resources\EngagementPriseEnChargeResource\Pages;

use App\Filament\Resources\EngagementPriseEnChargeResource;
use App\Filament\Resources\EngagementPriseEnChargeResource\EngagementActions;
use App\Models\EngagementPriseEnCharge;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;

/** Fiche d'un engagement de prise en charge (lot A2, §9.10) : suivi, auditeurs couverts, règlements, gestes. */
class ViewEngagement extends ViewRecord
{
    protected static string $resource = EngagementPriseEnChargeResource::class;

    public function getTitle(): string
    {
        /** @var EngagementPriseEnCharge $e */
        $e = $this->getRecord();

        return $e->organisme.' — lettre '.$e->libelleLettre();
    }

    protected function getHeaderActions(): array
    {
        return [
            EngagementActions::enregistrerReglement(),
            EngagementActions::rattacher(),
            EngagementActions::relancer(),
            EngagementActions::lettre(),
            ActionGroup::make([
                EngagementActions::prolonger(),
                EngagementActions::modifierContact(),
            ])->label('Engagement')->icon('heroicon-o-ellipsis-vertical')->button()->color('gray'),
        ];
    }
}
