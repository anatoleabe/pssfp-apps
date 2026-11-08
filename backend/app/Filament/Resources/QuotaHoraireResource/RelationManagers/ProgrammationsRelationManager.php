<?php

declare(strict_types=1);

namespace App\Filament\Resources\QuotaHoraireResource\RelationManagers;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\QuotaHoraireResource;
use App\Filament\Resources\QuotaHoraireResource\ChampsProgrammation;
use App\Models\ProgrammationEnseignant;
use App\Models\QuotaHoraire;
use App\Services\Lmd\PermissionsLmd;
use App\Services\Lmd\Programmations;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Programmations des enseignants sur un quota (lot 12d) : programmer, modifier, valider, notifier, annuler. */
#[BelongsToModule('lmd')]
class ProgrammationsRelationManager extends RelationManager
{
    use ChecksModuleAccess;

    protected static string $relationship = 'programmations';

    protected static ?string $title = 'Programmation des enseignants';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return static::moduleAllows() && (bool) auth()->user()?->can('view', $ownerRecord);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    private function quota(): QuotaHoraire
    {
        return $this->getOwnerRecord()->refresh();
    }

    private function peut(string $permission = PermissionsLmd::PROGRAMMATION): bool
    {
        return (bool) auth()->user()?->can($permission);
    }

    /** @return list<Forms\Components\Component> */
    private function champs(): array
    {
        $p = $this->getOwnerRecord()->periode;

        return MessagesValidation::appliquer([
            Forms\Components\Grid::make(3)->schema([
                Forms\Components\Select::make('enseignant_id')->label('Enseignant')->required()->native(false)->searchable()->columnSpan(2)
                    ->options(fn (): array => ChampsProgrammation::enseignants()),
                Forms\Components\Select::make('role')->label('Rôle')->native(false)->options(ProgrammationEnseignant::ROLES)->default('intervenant'),
                ...ChampsProgrammation::heures(),
                Forms\Components\DatePicker::make('debut_prevu')->label('Intervention du')->native(false)->displayFormat('d/m/Y')
                    ->minDate($p->debut_prevu)->maxDate($p->fin_prevue),
                Forms\Components\DatePicker::make('fin_prevue')->label('au')->native(false)->displayFormat('d/m/Y')
                    ->minDate($p->debut_prevu)->maxDate($p->fin_prevue)->afterOrEqual('debut_prevu'),
                Forms\Components\Select::make('modalite')->label('Modalité')->native(false)->options(ChampsProgrammation::modalites())->default('hybride'),
            ]),
        ], ['after_or_equal' => 'La fin de l\'intervention suit son début.']);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('enseignant'))
            ->defaultSort('id')
            ->columns([
                Tables\Columns\TextColumn::make('enseignant.name')->label('Enseignant')
                    ->description(fn (ProgrammationEnseignant $p): string => ProgrammationEnseignant::ROLES[$p->role]),
                Tables\Columns\TextColumn::make('heures')->label('Heures allouées')
                    ->getStateUsing(fn (ProgrammationEnseignant $p): string => ChampsProgrammation::heuresLibelle($p->heures_cm, $p->heures_td, $p->heures_tp)),
                Tables\Columns\TextColumn::make('fenetre')->label('Intervention')->getStateUsing(fn (ProgrammationEnseignant $p): string => $p->fenetre() ?? 'toute la période')
                    ->description(fn (ProgrammationEnseignant $p): string => ChampsProgrammation::modalites()[$p->modalite]),
                Tables\Columns\TextColumn::make('statut')->label('État')->badge()->formatStateUsing(fn (string $state): string => ProgrammationEnseignant::STATUTS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'accepte' => 'success', 'decline', 'annule' => 'danger', 'notifie' => 'info', default => 'warning'
                    })
                    ->description(fn (ProgrammationEnseignant $p): ?string => $p->motif),
            ])
            ->headerActions([
                Tables\Actions\Action::make('programmer')->label('Programmer un enseignant')->icon('heroicon-o-plus')
                    ->visible(fn (): bool => $this->peut())
                    ->form(fn (): array => $this->champs())
                    ->action(fn (array $data) => $this->executer(function () use ($data): string {
                        app(Programmations::class)->programmer($this->quota(), $data, auth()->user());

                        return 'Enseignant programmé.';
                    })),
            ])
            ->actions([Tables\Actions\ActionGroup::make([
                Tables\Actions\Action::make('modifier')->label('Modifier')->icon('heroicon-o-pencil')
                    ->visible(fn (ProgrammationEnseignant $p): bool => in_array($p->statut, ProgrammationEnseignant::MODIFIABLES, true) && $this->peut())
                    ->fillForm(fn (ProgrammationEnseignant $p): array => [...$p->only(['enseignant_id', 'role', 'heures_cm', 'heures_td', 'heures_tp', 'modalite']),
                        'debut_prevu' => $p->debut_prevu?->toDateString(), 'fin_prevue' => $p->fin_prevue?->toDateString()])
                    ->form(fn (): array => $this->champs())
                    ->action(fn (ProgrammationEnseignant $p, array $data) => $this->executer(function () use ($p, $data): string {
                        app(Programmations::class)->modifier($p, $data, auth()->user());

                        return 'Programmation modifiée : elle repasse à « proposée ».';
                    })),
                Tables\Actions\Action::make('valider')->label('Valider')->icon('heroicon-o-check-badge')->color('success')
                    ->visible(fn (ProgrammationEnseignant $p): bool => $p->statut === ProgrammationEnseignant::PROPOSE && $this->getOwnerRecord()->estValide()
                        && $this->peut(PermissionsLmd::PROGRAMMATION_VALIDER))
                    ->requiresConfirmation()
                    ->action(fn (ProgrammationEnseignant $p) => $this->executer(function () use ($p): string {
                        app(Programmations::class)->valider($p, auth()->user());

                        return 'Programmation validée.';
                    })),
                Tables\Actions\Action::make('notifier')->label('Notifier l\'enseignant')->icon('heroicon-o-envelope')
                    ->visible(fn (ProgrammationEnseignant $p): bool => $p->statut === ProgrammationEnseignant::VALIDE && $this->peut(PermissionsLmd::PROGRAMMATION_VALIDER))
                    ->requiresConfirmation()->modalDescription('L\'enseignant reçoit un courriel qui récapitule sa programmation ; il l\'accepte ou la décline dans le back-office.')
                    ->action(fn (ProgrammationEnseignant $p) => $this->executer(function () use ($p): string {
                        app(Programmations::class)->notifier([$p], auth()->user());

                        return 'Programmation notifiée.';
                    })),
                Tables\Actions\Action::make('annuler')->label('Annuler')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (ProgrammationEnseignant $p): bool => $p->statut !== ProgrammationEnseignant::ANNULE && $this->peut())
                    ->form(MessagesValidation::appliquer([Forms\Components\Textarea::make('motif')->label('Motif')->required()->minLength(5)->maxLength(500)]))
                    ->action(fn (ProgrammationEnseignant $p, array $data) => $this->executer(function () use ($p, $data): string {
                        app(Programmations::class)->annuler($p, (string) $data['motif'], auth()->user());

                        return 'Programmation annulée.';
                    })),
            ])->label('Actions')->icon('heroicon-o-ellipsis-vertical')]);
    }

    private function executer(callable $operation): void
    {
        ChampsProgrammation::executer($operation);
        // La section de consommation est sur la page : on la recharge.
        $this->redirect(QuotaHoraireResource::getUrl('view', ['record' => $this->quota()]));
    }
}
