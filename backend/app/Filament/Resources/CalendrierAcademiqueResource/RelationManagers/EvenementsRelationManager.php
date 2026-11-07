<?php

declare(strict_types=1);

namespace App\Filament\Resources\CalendrierAcademiqueResource\RelationManagers;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\CalendrierAcademiqueResource;
use App\Models\CalendrierAcademique;
use App\Models\DependanceEvenement;
use App\Models\EvenementCalendrier;
use App\Models\Parcours;
use App\Services\Lmd\Calendriers;
use App\Services\Lmd\PermissionsLmd;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** Événements du calendrier : préparation, réajustement motivé, exécution, liens (lot 12a). */
#[BelongsToModule('lmd')]
class EvenementsRelationManager extends RelationManager
{
    use ChecksModuleAccess;

    protected static string $relationship = 'evenements';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return static::moduleAllows() && (bool) auth()->user()?->can('view', $ownerRecord);
    }

    protected static ?string $title = 'Événements';

    public function isReadOnly(): bool
    {
        return false;
    }

    private function calendrier(): CalendrierAcademique
    {
        return $this->getOwnerRecord()->refresh();
    }

    /** @return list<Forms\Components\Component> */
    private function champs(bool $edition): array
    {
        $c = $this->calendrier();

        return MessagesValidation::appliquer([
            Forms\Components\Grid::make(3)->schema([
                Forms\Components\Select::make('type')->label('Type')->required()->native(false)->searchable()->options(EvenementCalendrier::TYPES)->live(),
                Forms\Components\TextInput::make('libelle')->label('Libellé')->required()->maxLength(255)->columnSpan(2),
                Forms\Components\Select::make('parent_id')->label('Phase')->native(false)
                    ->options(fn (): array => $c->evenements()->where('type', 'phase')->orderBy('ordre')->pluck('libelle', 'id')->all())
                    ->hidden(fn (Forms\Get $get): bool => $get('type') === 'phase'),
                Forms\Components\TextInput::make('ordre')->label('Ordre de la phase')->numeric()->minValue(1)->maxValue(99)
                    ->visible(fn (Forms\Get $get): bool => $get('type') === 'phase'),
                Forms\Components\Select::make('niveau')->label('Niveau')->native(false)->options(['M1' => 'M1', 'M2' => 'M2'])->placeholder('Commun'),
                Forms\Components\Select::make('session')->label('Session')->native(false)->options(['normale' => 'Normale', 'rattrapage' => 'Rattrapage']),
                Forms\Components\Select::make('parcours_id')->label('Parcours')->native(false)->placeholder('Tous')
                    ->options(fn (): array => Parcours::query()->orderBy('code')->pluck('libelle', 'id')->all()),
                Forms\Components\TextInput::make('promotion')->label('Promotion (numéro)')->numeric()->minValue(1)->maxValue(99),
                Forms\Components\TextInput::make('vague')->label('Vague (soutenances)')->numeric()->minValue(1)->maxValue(3),
                Forms\Components\DatePicker::make('debut_prevu')->label('Début prévu')->required()->native(false)->displayFormat('d/m/Y')
                    ->minDate($c->date_debut)->maxDate($c->date_fin),
                Forms\Components\DatePicker::make('fin_prevue')->label('Fin prévue')->native(false)->displayFormat('d/m/Y')
                    ->minDate($c->date_debut)->maxDate($c->date_fin)->afterOrEqual('debut_prevu')->helperText('Vide : un seul jour (jalon).'),
            ]),
            Forms\Components\Textarea::make('motif')->label('Motif du réajustement (calendrier signé)')->maxLength(500)
                ->visible($c->estSigne())->required($c->estSigne() && ! $edition),
        ], ['after_or_equal' => 'La fin doit suivre le début.', 'min' => 'Date hors de l\'année académique.', 'max' => 'Date hors de l\'année académique.']);
    }

    public function table(Table $table): Table
    {
        $peutModifier = fn (): bool => $this->calendrier()->estModifiable()
            && (bool) auth()->user()?->can($this->calendrier()->estSigne() ? PermissionsLmd::CALENDRIER_REAJUSTER : PermissionsLmd::CALENDRIER_PREPARER);

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('parent')->withCount('modifications')
                ->orderByRaw('CASE WHEN type = \'phase\' THEN 0 ELSE 1 END')->orderBy('ordre')->orderBy('debut_prevu')->orderBy('id'))
            ->recordTitleAttribute('libelle')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->columns([
                Tables\Columns\TextColumn::make('libelle')->label('Événement')->wrap()->searchable()
                    ->weight(fn (EvenementCalendrier $e): ?string => $e->estPhase() ? 'bold' : null)
                    ->description(fn (EvenementCalendrier $e): string => (EvenementCalendrier::TYPES[$e->type] ?? $e->type).($e->parent ? ' · '.$e->parent->libelle : '')),
                Tables\Columns\TextColumn::make('niveau')->label('Niveau')->placeholder('—'),
                Tables\Columns\TextColumn::make('prevu')->label('Prévu')
                    ->getStateUsing(fn (EvenementCalendrier $e): string => $e->debut_prevu->format('d/m/Y').($e->estJalon() || $e->estPhase() && $e->debut_prevu->equalTo($e->fin_prevue) ? '' : ' – '.$e->fin_prevue->format('d/m/Y'))),
                Tables\Columns\TextColumn::make('glissement')->label('Glissement')->placeholder('—')
                    ->getStateUsing(fn (EvenementCalendrier $e): ?string => ($g = $e->glissement()) ? ($g > 0 ? '+' : '').$g.' j' : null)
                    ->color(fn (?string $state): string => $state !== null && str_starts_with($state, '+') ? 'danger' : 'gray'),
                Tables\Columns\TextColumn::make('reel')->label('Réel')->placeholder('—')
                    ->getStateUsing(fn (EvenementCalendrier $e): ?string => $e->debut_reel ? $e->debut_reel->format('d/m/Y').($e->fin_reelle ? ' – '.$e->fin_reelle->format('d/m/Y') : '') : null),
                Tables\Columns\TextColumn::make('statut')->label('Exécution')->badge()
                    ->formatStateUsing(fn (string $state, EvenementCalendrier $e): string => $e->estPhase() ? 'Phase' : ($e->enRetard() ? 'En retard' : (EvenementCalendrier::STATUTS[$state] ?? $state)))
                    ->color(fn (string $state, EvenementCalendrier $e): string => $e->estPhase() ? 'gray' : ($e->enRetard() ? 'danger' : match ($state) {
                        'realise' => 'success', 'en_cours' => 'info', 'reporte' => 'warning', 'annule' => 'gray', default => 'gray'
                    })),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->label('Type')->options(EvenementCalendrier::TYPES),
                Tables\Filters\SelectFilter::make('niveau')->label('Niveau')->options(['M1' => 'M1', 'M2' => 'M2']),
                Tables\Filters\SelectFilter::make('statut')->label('Exécution')->options(EvenementCalendrier::STATUTS),
            ])
            ->headerActions([
                Tables\Actions\Action::make('ajouter')->label('Ajouter un événement')->icon('heroicon-o-plus')->visible($peutModifier)
                    ->form(fn (): array => $this->champs(false))->modalWidth('4xl')
                    ->action(fn (array $data) => $this->executer(function () use ($data): string {
                        app(Calendriers::class)->enregistrer($this->calendrier(), $data, auth()->user(), null, $data['motif'] ?? null);

                        return 'Événement ajouté.';
                    })),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('modifier')->label('Modifier')->icon('heroicon-o-pencil')->visible($peutModifier)
                        ->fillForm(fn (EvenementCalendrier $e): array => [...$e->only(['type', 'libelle', 'parent_id', 'ordre', 'niveau', 'session', 'parcours_id', 'promotion', 'vague']),
                            'debut_prevu' => $e->debut_prevu->toDateString(), 'fin_prevue' => $e->fin_prevue->toDateString()])
                        ->form(fn (): array => $this->champs(true))->modalWidth('4xl')
                        ->action(fn (EvenementCalendrier $e, array $data) => $this->executer(function () use ($e, $data): string {
                            app(Calendriers::class)->enregistrer($this->calendrier(), $data, auth()->user(), $e, $data['motif'] ?? null);

                            return 'Événement enregistré.';
                        })),
                    Tables\Actions\Action::make('executer')->label('Exécution')->icon('heroicon-o-play')
                        ->visible(fn (EvenementCalendrier $e): bool => ! $e->estPhase() && $this->calendrier()->estSigne() && (bool) auth()->user()?->can(PermissionsLmd::CALENDRIER_PREPARER))
                        ->fillForm(fn (EvenementCalendrier $e): array => ['statut' => $e->statut, 'debut_reel' => $e->debut_reel?->toDateString(), 'fin_reelle' => $e->fin_reelle?->toDateString(),
                            'avancement_pct' => $e->avancement_pct, 'motif' => $e->motif])
                        ->form(MessagesValidation::appliquer([
                            Forms\Components\Select::make('statut')->label('Statut')->required()->native(false)->options(EvenementCalendrier::STATUTS),
                            Forms\Components\DatePicker::make('debut_reel')->label('Début réel')->native(false)->displayFormat('d/m/Y'),
                            Forms\Components\DatePicker::make('fin_reelle')->label('Fin réelle')->native(false)->displayFormat('d/m/Y')->afterOrEqual('debut_reel'),
                            Forms\Components\TextInput::make('avancement_pct')->label('Avancement (%)')->numeric()->minValue(0)->maxValue(100),
                            Forms\Components\Textarea::make('motif')->label('Motif (report, annulation)')->maxLength(500),
                        ]))
                        ->action(fn (EvenementCalendrier $e, array $data) => $this->executer(function () use ($e, $data): string {
                            app(Calendriers::class)->executer($e, $data, auth()->user());

                            return 'Exécution enregistrée.';
                        })),
                    Tables\Actions\Action::make('lier')->label('Lier à un successeur')->icon('heroicon-o-link')
                        ->visible(fn (EvenementCalendrier $e): bool => ! $e->estPhase() && $peutModifier())
                        ->form(fn (EvenementCalendrier $e): array => MessagesValidation::appliquer([
                            Forms\Components\Select::make('successeur_id')->label('Successeur')->required()->searchable()
                                ->options($this->calendrier()->evenements()->where('type', '<>', 'phase')->where('id', '<>', $e->id)->orderBy('debut_prevu')->pluck('libelle', 'id')->all()),
                            Forms\Components\Select::make('type_lien')->label('Type de lien')->required()->native(false)->options(DependanceEvenement::TYPES)->default('fin_debut'),
                            Forms\Components\TextInput::make('min')->label('Écart minimal (jours)')->required()->numeric()->default(0),
                            Forms\Components\TextInput::make('max')->label('Écart maximal (jours, vide : sans limite)')->numeric(),
                            Forms\Components\Toggle::make('bloquant')->label('Bloquant (empêche la signature s\'il est violé)')->default(true),
                        ]))
                        ->action(fn (EvenementCalendrier $e, array $data) => $this->executer(function () use ($e, $data): string {
                            app(Calendriers::class)->lier($e, EvenementCalendrier::query()->findOrFail($data['successeur_id']), (string) $data['type_lien'],
                                (int) $data['min'], $data['max'] === null || $data['max'] === '' ? null : (int) $data['max'], (bool) $data['bloquant'], auth()->user());

                            return 'Lien enregistré.';
                        })),
                    Tables\Actions\Action::make('supprimer')->label('Supprimer')->icon('heroicon-o-trash')->color('danger')->requiresConfirmation()
                        ->visible(fn (): bool => $this->calendrier()->statut === CalendrierAcademique::BROUILLON && (bool) auth()->user()?->can(PermissionsLmd::CALENDRIER_PREPARER))
                        ->action(fn (EvenementCalendrier $e) => $this->executer(function () use ($e): string {
                            app(Calendriers::class)->supprimer($e, auth()->user());

                            return 'Événement supprimé.';
                        })),
                ]),
            ]);
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
        // Le Gantt et les contrôles de la fiche se recalculent au rechargement.
        $this->redirect(CalendrierAcademiqueResource::getUrl('view', ['record' => $this->calendrier()]));
    }
}
