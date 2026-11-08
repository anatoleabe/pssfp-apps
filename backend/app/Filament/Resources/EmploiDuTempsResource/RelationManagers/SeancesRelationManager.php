<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmploiDuTempsResource\RelationManagers;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\EmploiDuTempsResource;
use App\Models\EmploiDuTemps;
use App\Models\MaquetteEcue;
use App\Models\PlageHoraire;
use App\Models\Salle;
use App\Models\Seance;
use App\Models\User;
use App\Services\Lmd\EmploisDuTemps;
use App\Services\Lmd\PermissionsLmd;
use App\Support\BelongsToModule;
use Carbon\CarbonImmutable;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** Séances programmées d'un emploi du temps (lot 12c) : ajout avec répétition, modification, report, annulation. */
#[BelongsToModule('lmd')]
class SeancesRelationManager extends RelationManager
{
    use ChecksModuleAccess;

    protected static string $relationship = 'seances';

    protected static ?string $title = 'Séances';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return static::moduleAllows() && (bool) auth()->user()?->can('view', $ownerRecord);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    private function edt(): EmploiDuTemps
    {
        return $this->getOwnerRecord()->refresh();
    }

    private function peut(): bool
    {
        return (bool) auth()->user()?->can(PermissionsLmd::EMPLOIS_DU_TEMPS);
    }

    /** @return list<Forms\Components\Component> */
    private function champs(bool $repetition): array
    {
        $e = $this->edt();

        return MessagesValidation::appliquer([
            Forms\Components\Grid::make(3)->schema([
                Forms\Components\Select::make('maquette_ecue_id')->label('ECUE')->required()->native(false)->searchable()->columnSpan(2)
                    ->options(fn (): array => app(EmploisDuTemps::class)->ecues($e)->mapWithKeys(fn (MaquetteEcue $x): array => [$x->id => $x->code.' — '.$x->intitule])->all()),
                Forms\Components\Select::make('nature')->label('Nature')->native(false)->options(Seance::NATURES)->default('CM'),
                Forms\Components\DatePicker::make('date_seance')->label('Date')->required()->native(false)->displayFormat('l d/m/Y')
                    ->minDate($e->periode->debut_prevu)->maxDate($e->periode->fin_prevue),
                Forms\Components\Select::make('plage_horaire_id')->label('Plage horaire')->native(false)->placeholder('Heures libres')->live()
                    ->options(fn (): array => PlageHoraire::query()->orderBy('ordre')->get()->mapWithKeys(fn (PlageHoraire $p): array => [$p->id => $p->libelle()])->all()),
                Forms\Components\Select::make('modalite')->label('Modalité')->required()->native(false)->options(Seance::MODALITES)->default('presentiel'),
                Forms\Components\TimePicker::make('debut')->label('Début')->seconds(false)->required(fn (Forms\Get $get): bool => blank($get('plage_horaire_id')))
                    ->visible(fn (Forms\Get $get): bool => blank($get('plage_horaire_id'))),
                Forms\Components\TimePicker::make('fin')->label('Fin')->seconds(false)->after('debut')->required(fn (Forms\Get $get): bool => blank($get('plage_horaire_id')))
                    ->visible(fn (Forms\Get $get): bool => blank($get('plage_horaire_id'))),
                Forms\Components\Select::make('salle_id')->label('Salle')->native(false)->searchable()
                    ->options(fn (): array => Salle::query()->where('active', true)->orderBy('code')->get()->mapWithKeys(fn (Salle $s): array => [$s->id => $s->libelleComplet()])->all()),
                Forms\Components\Select::make('enseignant_id')->label('Enseignant')->native(false)->searchable()->columnSpan(2)
                    ->options(fn (): array => User::role('enseignant')->orderBy('name')->pluck('name', 'id')->all()),
                Forms\Components\DatePicker::make('jusquau')->label('Répéter chaque semaine jusqu\'au')->native(false)->displayFormat('d/m/Y')
                    ->minDate($e->periode->debut_prevu)->maxDate($e->periode->fin_prevue)->afterOrEqual('date_seance')->visible($repetition)
                    ->helperText('Les jours non ouvrés, l\'interruption et les examens du niveau sont sautés.'),
            ]),
        ], ['after' => 'La fin doit suivre le début.', 'after_or_equal' => 'La répétition se termine après la première séance.']);
    }

    public function table(Table $table): Table
    {
        $e = $this->edt();
        $semaines = [];
        for ($l = $e->periode->debut_prevu->startOfWeek(); $l->lte($e->periode->fin_prevue); $l = $l->addWeek()) {
            $semaines[$l->toDateString()] = 'Semaine du '.$l->format('d/m/Y');
        }

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['ecue', 'enseignant', 'salleAffectee']))
            ->defaultSort('date_seance')
            ->defaultGroup(Group::make('date_seance')->label('Jour')->titlePrefixedWithLabel(false)->collapsible()
                ->getTitleFromRecordUsing(fn (Seance $s): string => ucfirst($s->date_seance->locale('fr')->translatedFormat('l j F Y'))))
            ->columns([
                Tables\Columns\TextColumn::make('heures')->label('Heures')
                    ->getStateUsing(fn (Seance $s): string => substr((string) $s->debut, 0, 5).' – '.substr((string) $s->fin, 0, 5)),
                Tables\Columns\TextColumn::make('ecue.code')->label('ECUE')->description(fn (Seance $s): string => (string) $s->ecue?->intitule)->wrap(),
                Tables\Columns\TextColumn::make('nature')->label('Nature')->placeholder('—'),
                Tables\Columns\TextColumn::make('enseignant.name')->label('Enseignant')->placeholder('—'),
                Tables\Columns\TextColumn::make('salle')->label('Salle')->placeholder('—'),
                Tables\Columns\TextColumn::make('modalite')->label('Modalité')->formatStateUsing(fn (string $state): string => Seance::MODALITES[$state] ?? $state),
                Tables\Columns\TextColumn::make('statut')->label('État')->badge()->formatStateUsing(fn (string $state): string => Seance::STATUTS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'tenue' => 'success', 'annulee', 'reportee' => 'gray', default => 'warning'
                    })
                    ->description(fn (Seance $s): ?string => $s->motif_report ?? $s->motif_annulation),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('semaine')->label('Semaine')->options($semaines)
                    ->query(fn (Builder $query, array $data): Builder => empty($data['value']) ? $query
                        : $query->whereBetween('date_seance', [$data['value'], CarbonImmutable::parse($data['value'])->addDays(6)->toDateString()])),
                Tables\Filters\SelectFilter::make('statut')->label('État')->options(Seance::STATUTS),
            ])
            ->headerActions([
                Tables\Actions\Action::make('programmer')->label('Programmer une séance')->icon('heroicon-o-plus')
                    ->visible(fn (): bool => $this->peut())
                    ->form(fn (): array => $this->champs(true))
                    ->action(fn (array $data) => $this->executer(function () use ($data): string {
                        $b = app(EmploisDuTemps::class)->programmer($this->edt(), $data, auth()->user(), $data['jusquau'] ?? null);

                        return $b['creees'].' séance(s) programmée(s)'.($b['sautees'] !== [] ? ' ; sautée(s) : '.implode(', ', $b['sautees']) : '').'.';
                    })),
            ])
            ->actions([Tables\Actions\ActionGroup::make([
                Tables\Actions\Action::make('modifier')->label('Modifier')->icon('heroicon-o-pencil')
                    ->visible(fn (Seance $s): bool => $s->statut === 'prevue' && $this->peut())
                    ->fillForm(fn (Seance $s): array => [...$s->only(['maquette_ecue_id', 'nature', 'plage_horaire_id', 'modalite', 'salle_id', 'enseignant_id']),
                        'date_seance' => $s->date_seance->toDateString(), 'debut' => substr((string) $s->debut, 0, 5), 'fin' => substr((string) $s->fin, 0, 5)])
                    ->form(fn (): array => $this->champs(false))
                    ->action(fn (Seance $s, array $data) => $this->executer(function () use ($s, $data): string {
                        app(EmploisDuTemps::class)->modifier($s, $data, auth()->user());

                        return 'Séance modifiée.';
                    })),
                Tables\Actions\Action::make('reporter')->label('Reporter')->icon('heroicon-o-arrow-right-circle')
                    ->visible(fn (Seance $s): bool => $s->statut === 'prevue' && $this->peut())
                    ->form(fn (): array => MessagesValidation::appliquer([
                        Forms\Components\Textarea::make('motif')->label('Motif du report')->required()->minLength(5)->maxLength(500),
                        Forms\Components\DatePicker::make('date_seance')->label('Nouvelle date')->required()->native(false)->displayFormat('l d/m/Y'),
                        Forms\Components\Select::make('plage_horaire_id')->label('Nouvelle plage (vide : mêmes heures)')->native(false)
                            ->options(fn (): array => PlageHoraire::query()->orderBy('ordre')->get()->mapWithKeys(fn (PlageHoraire $p): array => [$p->id => $p->libelle()])->all()),
                    ]))
                    ->action(fn (Seance $s, array $data) => $this->executer(function () use ($s, $data): string {
                        $r = app(EmploisDuTemps::class)->reporter($s, (string) $data['motif'],
                            ['date_seance' => $data['date_seance'], 'plage_horaire_id' => $data['plage_horaire_id'] ?? null], auth()->user());

                        return 'Séance reportée au '.$r->date_seance->format('d/m/Y').'.';
                    })),
                Tables\Actions\Action::make('annuler')->label('Annuler')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (Seance $s): bool => $s->statut === 'prevue' && $this->edt()->statut === EmploiDuTemps::PUBLIE && $this->peut())
                    ->form(MessagesValidation::appliquer([Forms\Components\Textarea::make('motif')->label('Motif')->required()->minLength(5)->maxLength(500)]))
                    ->action(fn (Seance $s, array $data) => $this->executer(function () use ($s, $data): string {
                        app(EmploisDuTemps::class)->annuler($s, (string) $data['motif'], auth()->user());

                        return 'Séance annulée.';
                    })),
                Tables\Actions\Action::make('supprimer')->label('Supprimer')->icon('heroicon-o-trash')->color('danger')->requiresConfirmation()
                    ->visible(fn (): bool => $this->edt()->statut === EmploiDuTemps::BROUILLON && $this->peut())
                    ->action(fn (Seance $s) => $this->executer(function () use ($s): string {
                        app(EmploisDuTemps::class)->supprimer($s, auth()->user());

                        return 'Séance supprimée.';
                    })),
            ])->label('Actions')->icon('heroicon-o-ellipsis-vertical')]);
    }

    private function executer(callable $operation): void
    {
        try {
            $message = $operation();
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Opération refusée')->body($e->getMessage())->persistent()->send();

            return;
        }
        Notification::make()->success()->title($message)->send();
        // La section des conflits est sur la page : on la recharge.
        $this->redirect(EmploiDuTempsResource::getUrl('view', ['record' => $this->edt()]));
    }
}
