<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmploiDuTempsResource\Pages;

use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\EmploiDuTempsResource;
use App\Models\CalendrierAcademique;
use App\Models\EvenementCalendrier;
use App\Models\Parcours;
use App\Services\Lmd\EmploisDuTemps;
use App\Services\Lmd\PermissionsLmd;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use InvalidArgumentException;

class ListEmploisDuTemps extends ListRecords
{
    protected static string $resource = EmploiDuTempsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('creer')->label('Nouvel emploi du temps')->icon('heroicon-o-plus')
                ->visible(fn (): bool => (bool) auth()->user()?->can(PermissionsLmd::EMPLOIS_DU_TEMPS))
                ->form(MessagesValidation::appliquer([
                    Forms\Components\Select::make('periode')->label('Période de cours (calendrier signé)')->required()->native(false)->searchable()
                        ->options(fn (): array => EvenementCalendrier::query()->where('type', 'periode_cours')->where('statut', '<>', 'annule')
                            ->whereHas('calendrier', fn ($q) => $q->whereIn('statut', [CalendrierAcademique::SIGNE, CalendrierAcademique::PUBLIE]))
                            ->orderBy('debut_prevu')->get()
                            ->mapWithKeys(fn (EvenementCalendrier $e): array => [$e->id => $e->libelle.' ('.$e->debut_prevu->format('d/m/Y').' – '.$e->fin_prevue->format('d/m/Y').')'])->all()),
                    Forms\Components\Select::make('parcours')->label('Parcours')->required()->native(false)
                        ->options(fn (): array => Parcours::query()->where('actif', true)->orderBy('niveau')->orderBy('ordre')->get()
                            ->mapWithKeys(fn (Parcours $p): array => [$p->id => $p->niveau.' — '.$p->libelle])->all()),
                ]))
                ->action(function (array $data): void {
                    try {
                        $e = app(EmploisDuTemps::class)->creer(EvenementCalendrier::query()->findOrFail($data['periode']), Parcours::query()->findOrFail($data['parcours']), auth()->user());
                    } catch (InvalidArgumentException $ex) {
                        Notification::make()->danger()->title('Opération refusée')->body($ex->getMessage())->send();

                        return;
                    }
                    $this->redirect(EmploiDuTempsResource::getUrl('view', ['record' => $e]));
                }),
        ];
    }
}
