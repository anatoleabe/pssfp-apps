<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmploiDuTempsResource\Pages;

use App\Filament\Resources\EmploiDuTempsResource;
use App\Models\EmploiDuTemps;
use App\Services\Lmd\EmploisDuTemps;
use App\Services\Lmd\PermissionsLmd;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use InvalidArgumentException;

class ViewEmploiDuTemps extends ViewRecord
{
    protected static string $resource = EmploiDuTempsResource::class;

    public function getTitle(): string
    {
        return 'Emploi du temps '.$this->getRecord()->parcours->code;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('publier')->label('Publier')->icon('heroicon-o-megaphone')->color('success')
                ->visible(fn (): bool => $this->getRecord()->statut === EmploiDuTemps::BROUILLON && (bool) auth()->user()?->can(PermissionsLmd::EMPLOIS_DU_TEMPS))
                ->requiresConfirmation()->modalDescription('Les auditeurs du parcours voient l\'emploi du temps dans leur espace. La publication est refusée tant qu\'il reste un conflit bloquant.')
                ->action(function (): void {
                    try {
                        app(EmploisDuTemps::class)->publier($this->getRecord()->refresh(), auth()->user());
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->danger()->title('Publication refusée')->body($e->getMessage())->persistent()->send();

                        return;
                    }
                    Notification::make()->success()->title('Emploi du temps publié.')->send();
                    $this->redirect(EmploiDuTempsResource::getUrl('view', ['record' => $this->getRecord()]));
                }),
        ];
    }
}
