<?php

declare(strict_types=1);

namespace App\Filament\Resources\QuotaHoraireResource\Pages;

use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\QuotaHoraireResource;
use App\Filament\Resources\QuotaHoraireResource\ChampsProgrammation;
use App\Models\EvenementCalendrier;
use App\Models\MaquetteEcue;
use App\Models\Parcours;
use App\Services\Lmd\PermissionsLmd;
use App\Services\Lmd\Programmations;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Resources\Pages\ListRecords;

class ListQuotasHoraires extends ListRecords
{
    protected static string $resource = QuotaHoraireResource::class;

    protected function getHeaderActions(): array
    {
        $peut = fn (): bool => (bool) auth()->user()?->can(PermissionsLmd::PROGRAMMATION);
        $periode = fn () => Forms\Components\Select::make('periode')->label('Période de cours (calendrier signé)')->required()->native(false)->searchable()->live()
            ->options(fn (): array => ChampsProgrammation::periodes())
            ->afterStateUpdated(fn (Forms\Set $set) => $set('ecue', null));

        return [
            Action::make('proposer')->label('Proposer les quotas d\'une période')->icon('heroicon-o-calculator')->color('gray')->visible($peut)
                ->modalDescription('Pour chaque ECUE retenu sans quota dans la période, la plateforme propose le volume de la maquette moins les quotas déjà fixés dans les autres périodes de l\'année.')
                ->form(MessagesValidation::appliquer([
                    $periode(),
                    Forms\Components\CheckboxList::make('parcours')->label('Parcours')->required()->columns(3)
                        ->options(fn (): array => Parcours::query()->where('actif', true)->orderBy('niveau')->orderBy('ordre')->get()
                            ->mapWithKeys(fn (Parcours $p): array => [$p->id => $p->code.' ('.$p->niveau.')'])->all()),
                    Forms\Components\CheckboxList::make('semestres')->label('Semestres de la maquette')->required()->columns(4)
                        ->options([7 => 'Semestre 7', 8 => 'Semestre 8', 9 => 'Semestre 9', 10 => 'Semestre 10']),
                ]))
                ->action(fn (array $data) => ChampsProgrammation::executer(function () use ($data): string {
                    $b = app(Programmations::class)->proposerQuotas(EvenementCalendrier::query()->findOrFail($data['periode']),
                        array_map('intval', $data['parcours']), array_map('intval', $data['semestres']), auth()->user());

                    return $b['creees'].' quota(s) proposé(s)'.($b['epuises'] !== [] ? ' ; volume déjà réparti : '.implode(', ', $b['epuises']) : '').'.';
                })),
            Action::make('ajouter')->label('Ajouter un quota')->icon('heroicon-o-plus')->visible($peut)
                ->form(MessagesValidation::appliquer([
                    $periode(),
                    Forms\Components\Select::make('ecue')->label('ECUE')->required()->native(false)->searchable()
                        ->options(function (Forms\Get $get): array {
                            $p = EvenementCalendrier::query()->find($get('periode'));

                            // Un quota existant se change par « Modifier » sur sa ligne (motif s'il est validé).
                            return $p === null ? [] : app(Programmations::class)->ecuesPeriode($p)
                                ->reject(fn (MaquetteEcue $x): bool => $p->quotas()->where('maquette_ecue_id', $x->id)->exists())
                                ->mapWithKeys(fn (MaquetteEcue $x): array => [$x->id => $x->code.' — '.$x->intitule.' ('.$x->maquetteUe->maquette->parcours->code.')'])->all();
                        }),
                    Forms\Components\Grid::make(3)->schema(ChampsProgrammation::heures()),
                ]))
                ->action(fn (array $data) => ChampsProgrammation::executer(function () use ($data): string {
                    app(Programmations::class)->definirQuota(EvenementCalendrier::query()->findOrFail($data['periode']),
                        MaquetteEcue::query()->findOrFail($data['ecue']), $data, auth()->user());

                    return 'Quota enregistré.';
                })),
        ];
    }
}
