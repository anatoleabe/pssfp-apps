<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlanningExamensResource\Pages;

use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\PlanningExamensResource;
use App\Models\CalendrierAcademique;
use App\Models\EvenementCalendrier;
use App\Models\PlanningExamens;
use App\Services\Lmd\PermissionsLmd;
use App\Services\Lmd\PlanningsExamens;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use InvalidArgumentException;

class ListPlanningsExamens extends ListRecords
{
    protected static string $resource = PlanningExamensResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('creer')->label('Nouveau planning')->icon('heroicon-o-plus')
                ->visible(fn (): bool => (bool) auth()->user()?->can(PermissionsLmd::EMPLOIS_DU_TEMPS))
                ->form(MessagesValidation::appliquer([
                    Forms\Components\Select::make('session')->label('Session d\'examens (calendrier signé)')->required()->native(false)->searchable()
                        ->helperText('Les sessions qui ont déjà un planning ne sont pas proposées.')
                        ->options(fn (): array => EvenementCalendrier::query()->whereIn('type', PlanningExamens::TYPES_SESSION)->where('statut', '<>', 'annule')
                            ->whereHas('calendrier', fn ($q) => $q->whereIn('statut', [CalendrierAcademique::SIGNE, CalendrierAcademique::PUBLIE]))
                            ->whereNotIn('id', PlanningExamens::query()->select('session_id'))
                            ->orderBy('debut_prevu')->get()
                            ->mapWithKeys(fn (EvenementCalendrier $e): array => [$e->id => $e->libelle.' ('.$e->debut_prevu->format('d/m/Y').' – '.$e->fin_prevue->format('d/m/Y').')'])->all()),
                ]))
                ->action(function (array $data): void {
                    try {
                        $p = app(PlanningsExamens::class)->creer(EvenementCalendrier::query()->findOrFail($data['session']), auth()->user());
                    } catch (InvalidArgumentException $ex) {
                        Notification::make()->danger()->title('Opération refusée')->body($ex->getMessage())->send();

                        return;
                    }
                    $this->redirect(PlanningExamensResource::getUrl('view', ['record' => $p]));
                }),
        ];
    }
}
