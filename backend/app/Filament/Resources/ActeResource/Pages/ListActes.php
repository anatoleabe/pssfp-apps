<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActeResource\Pages;

use App\Filament\Resources\ActeResource;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Models\CalendrierAcademique;
use App\Services\Lmd\Actes;
use App\Services\Lmd\PermissionsLmd;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use InvalidArgumentException;

class ListActes extends ListRecords
{
    protected static string $resource = ActeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generer')->label('Générer les actes d\'un calendrier')->icon('heroicon-o-document-plus')
                ->visible(fn (): bool => (bool) auth()->user()?->can(PermissionsLmd::ACTES_PRODUIRE))
                ->modalDescription('Un acte par événement et par modèle actif ; les actes déjà générés ne sont pas recréés.')
                ->form(MessagesValidation::appliquer([
                    Forms\Components\Select::make('calendrier')->label('Calendrier signé')->required()
                        ->options(fn (): array => CalendrierAcademique::query()->whereIn('statut', [CalendrierAcademique::SIGNE, CalendrierAcademique::PUBLIE])
                            ->orderByDesc('id')->get()->mapWithKeys(fn (CalendrierAcademique $c): array => [$c->id => $c->libelle()])->all()),
                ]))
                ->action(function (array $data): void {
                    try {
                        $n = app(Actes::class)->generer(CalendrierAcademique::query()->findOrFail($data['calendrier']), auth()->user());
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->danger()->title('Opération refusée')->body($e->getMessage())->send();

                        return;
                    }
                    Notification::make()->success()->title($n.' acte(s) généré(s).')->send();
                }),
        ];
    }
}
