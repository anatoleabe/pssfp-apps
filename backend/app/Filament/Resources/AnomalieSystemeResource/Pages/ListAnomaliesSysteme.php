<?php

declare(strict_types=1);

namespace App\Filament\Resources\AnomalieSystemeResource\Pages;

use App\Filament\Resources\AnomalieSystemeResource;
use App\Filament\Resources\AnomalieSystemeResource\Widgets\TableauControles;
use App\Services\Supervision\Supervision;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListAnomaliesSysteme extends ListRecords
{
    protected static string $resource = AnomalieSystemeResource::class;

    protected function getHeaderWidgets(): array
    {
        return [TableauControles::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('executer')->label('Exécuter les contrôles')->icon('heroicon-o-play')
                ->visible(fn (): bool => Supervision::peutTraiter(auth()->user()))
                ->requiresConfirmation()->modalDescription('Les contrôles s\'exécutent chaque jour à 05:30. Cette exécution met les anomalies à jour tout de suite.')
                ->action(function (): void {
                    $bilan = app(Supervision::class)->executer();
                    $erreurs = collect($bilan)->filter(fn (array $b): bool => $b['erreur'] !== null)->count();
                    Notification::make()->title('Contrôles exécutés')
                        ->body(collect($bilan)->sum('nouveaux').' nouvelle(s) anomalie(s), '.collect($bilan)->sum('corriges').' corrigée(s)'
                            .($erreurs > 0 ? ', '.$erreurs.' contrôle(s) en erreur' : '').'.')
                        ->{$erreurs > 0 ? 'warning' : 'success'}()->send();
                }),
        ];
    }
}
