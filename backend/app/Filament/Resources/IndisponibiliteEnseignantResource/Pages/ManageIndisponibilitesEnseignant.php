<?php

declare(strict_types=1);

namespace App\Filament\Resources\IndisponibiliteEnseignantResource\Pages;

use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\IndisponibiliteEnseignantResource;
use App\Filament\Resources\QuotaHoraireResource\ChampsProgrammation;
use App\Models\IndisponibiliteEnseignant;
use App\Models\User;
use App\Services\Lmd\Programmations;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Resources\Pages\ManageRecords;

class ManageIndisponibilitesEnseignant extends ManageRecords
{
    protected static string $resource = IndisponibiliteEnseignantResource::class;

    public function getTitle(): string
    {
        return IndisponibiliteEnseignantResource::getNavigationLabel();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('declarer')->label('Déclarer une indisponibilité')->icon('heroicon-o-plus')
                ->visible(fn (): bool => (bool) auth()->user()?->can('create', IndisponibiliteEnseignant::class))
                ->form(MessagesValidation::appliquer([
                    Forms\Components\Select::make('enseignant_id')->label('Enseignant')->required()->native(false)->searchable()
                        ->options(fn (): array => ChampsProgrammation::enseignants())->visible(fn (): bool => IndisponibiliteEnseignantResource::gestion()),
                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\DateTimePicker::make('debut')->label('Du')->required()->native(false)->seconds(false)->displayFormat('d/m/Y H:i'),
                        Forms\Components\DateTimePicker::make('fin')->label('Au')->required()->native(false)->seconds(false)->displayFormat('d/m/Y H:i')->after('debut'),
                    ]),
                    Forms\Components\TextInput::make('motif')->label('Motif (facultatif)')->maxLength(255)
                        ->helperText('Restez général (« mission », « jury ») : aucune information médicale ou personnelle.'),
                ], ['after' => 'La fin suit le début.']))
                ->action(fn (array $data) => ChampsProgrammation::executer(function () use ($data): string {
                    $enseignant = IndisponibiliteEnseignantResource::gestion() ? User::query()->findOrFail($data['enseignant_id']) : auth()->user();
                    app(Programmations::class)->declarerIndisponibilite($enseignant, (string) $data['debut'], (string) $data['fin'], $data['motif'] ?? null, auth()->user());

                    return 'Indisponibilité enregistrée.';
                })),
        ];
    }
}
