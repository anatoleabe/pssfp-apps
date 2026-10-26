<?php

declare(strict_types=1);

namespace App\Filament\Resources\CampagneCandidatureResource\Pages;

use App\Filament\Resources\CampagneCandidatureResource;
use App\Filament\Resources\CampagneCandidatureResource\ExportsRetenusActions;
use App\Filament\Resources\CampagneCandidatureResource\ResultatsSelectionActions;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCampagneCandidature extends EditRecord
{
    protected static string $resource = CampagneCandidatureResource::class;

    protected function getHeaderActions(): array
    {
        // Pas de delete : FK RESTRICT bloque côté DB et la policy retourne false.
        return [
            Actions\ActionGroup::make([
                ResultatsSelectionActions::communique(Actions\Action::make('communiqueResultats')),
                ResultatsSelectionActions::pv(Actions\Action::make('pvJury')),
                ResultatsSelectionActions::liste(Actions\Action::make('listeRetenus')),
            ])
                ->label('Résultats de sélection')
                ->icon('heroicon-o-trophy')
                ->button()
                ->color('gray'),
            Actions\ActionGroup::make([
                ExportsRetenusActions::attribuer(Actions\Action::make('attribuerMatricules')),
                ExportsRetenusActions::fullData(Actions\Action::make('exportFullData')),
                ExportsRetenusActions::acma(Actions\Action::make('exportAcma')),
                ExportsRetenusActions::moodle(Actions\Action::make('exportMoodle')),
            ])
                ->label('Retenus : matricules et exports')
                ->icon('heroicon-o-identification')
                ->button()
                ->color('gray'),
        ];
    }
}
