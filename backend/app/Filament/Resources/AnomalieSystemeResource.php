<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\AnomalieSystemeResource\Pages;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\QuotaHoraireResource\ChampsProgrammation;
use App\Models\AnomalieSysteme;
use App\Services\Supervision\Controle;
use App\Services\Supervision\Supervision;
use App\Support\BelongsToModule;
use Carbon\CarbonImmutable;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Lot 14a (D30) : contrôle interne. Anomalies détectées par les contrôles automatiques, leur traitement,
 * et le tableau de bord par contrôle (en-tête de la liste).
 */
#[BelongsToModule('administration')]
class AnomalieSystemeResource extends Resource
{
    use ChecksModuleAccess;

    protected static ?string $model = AnomalieSysteme::class;

    protected static ?string $modelLabel = 'anomalie';

    protected static ?string $pluralModelLabel = 'Contrôle interne';

    protected static ?string $navigationLabel = 'Contrôle interne';

    protected static ?string $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?string $navigationGroup = GroupeNavigation::ADMINISTRATION;

    protected static ?int $navigationSort = 50;

    protected static ?string $slug = 'controle-interne';

    public static function canAccess(): bool
    {
        return static::moduleAllows() && Supervision::peutConsulter(auth()->user());
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! Supervision::peutConsulter(auth()->user())) {
            return null;
        }
        $n = app(Supervision::class)->nombreEnRetard();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Anomalies du contrôle interne en retard';
    }

    public static function table(Table $table): Table
    {
        $controles = fn (): array => app(Supervision::class)->controles();
        $aujourdhui = fn (): string => CarbonImmutable::now(Supervision::FUSEAU)->toDateString();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withMax('relances', 'niveau'))
            ->defaultSort('echeance')
            ->columns([
                Tables\Columns\TextColumn::make('controle_code')->label('Contrôle')->wrap()
                    ->formatStateUsing(fn (string $state): string => ($controles()[$state] ?? null)?->libelle() ?? $state)
                    ->description(fn (AnomalieSysteme $a): string => (string) ($a->detail['resume'] ?? '')),
                Tables\Columns\TextColumn::make('criticite')->label('Criticité')->badge()
                    ->getStateUsing(fn (AnomalieSysteme $a): string => ($controles()[$a->controle_code] ?? null)?->criticite() ?? '—')
                    ->color(fn (string $state): string => match ($state) {
                        Controle::BLOQUANT => 'danger',
                        Controle::MAJEUR => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('statut')->label('Statut')->badge()
                    ->formatStateUsing(fn (string $state): string => AnomalieSysteme::STATUTS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        AnomalieSysteme::OUVERTE => 'danger',
                        AnomalieSysteme::EN_TRAITEMENT => 'warning',
                        AnomalieSysteme::JUSTIFIEE => 'info',
                        default => 'success',
                    }),
                Tables\Columns\TextColumn::make('echeance')->label('Échéance')->date('d/m/Y')->sortable()
                    ->color(fn (AnomalieSysteme $a): ?string => in_array($a->statut, AnomalieSysteme::A_TRAITER, true)
                        && $a->echeance->toDateString() < $aujourdhui() ? 'danger' : null),
                Tables\Columns\TextColumn::make('relances_max_niveau')->label('Relance')->badge()->placeholder('—')
                    ->formatStateUsing(fn ($state): string => 'Niveau '.$state)
                    ->color(fn ($state): string => (int) $state >= 3 ? 'danger' : ((int) $state === 2 ? 'warning' : 'gray')),
                Tables\Columns\TextColumn::make('premiere_detection')->label('Détectée le')->dateTime('d/m/Y', Supervision::FUSEAU)->sortable(),
                Tables\Columns\TextColumn::make('assigneA.name')->label('Pris en charge par')->placeholder('—'),
                Tables\Columns\TextColumn::make('justification')->label('Justification')->limit(60)->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('statut')->label('Statut')->multiple()->options(AnomalieSysteme::STATUTS)
                    ->default(AnomalieSysteme::A_TRAITER),
                Tables\Filters\SelectFilter::make('controle_code')->label('Contrôle')->multiple()
                    ->options(fn (): array => collect($controles())->map(fn (Controle $c): string => $c->libelle())->all()),
                Tables\Filters\SelectFilter::make('criticite')->label('Criticité')
                    ->options([Controle::BLOQUANT => 'Bloquant', Controle::MAJEUR => 'Majeur', Controle::MINEUR => 'Mineur'])
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $q, string $c): Builder => $q->whereIn(
                        'controle_code',
                        collect($controles())->filter(fn (Controle $x): bool => $x->criticite() === $c)->keys()->all(),
                    ))),
            ])
            ->actions([
                Tables\Actions\Action::make('prendreEnCharge')->label('Prendre en charge')->icon('heroicon-o-hand-raised')
                    ->visible(fn (AnomalieSysteme $a): bool => $a->statut === AnomalieSysteme::OUVERTE && Supervision::peutTraiter(auth()->user()))
                    ->requiresConfirmation()
                    ->action(fn (AnomalieSysteme $a) => ChampsProgrammation::executer(function () use ($a): string {
                        app(Supervision::class)->prendreEnCharge($a, auth()->user());

                        return 'Anomalie prise en charge.';
                    })),
                Tables\Actions\Action::make('justifier')->label('Justifier')->icon('heroicon-o-check-badge')->color('gray')
                    ->visible(fn (AnomalieSysteme $a): bool => in_array($a->statut, AnomalieSysteme::A_TRAITER, true) && Supervision::peutTraiter(auth()->user()))
                    ->modalDescription('L\'anomalie est acceptée : elle reste justifiée tant que le contrôle la détecte. Le motif est journalisé.')
                    ->form(MessagesValidation::appliquer([
                        Forms\Components\Textarea::make('motif')->label('Justification')->required()->minLength(Supervision::MOTIF_MIN)->maxLength(1000),
                    ]))
                    ->action(fn (AnomalieSysteme $a, array $data) => ChampsProgrammation::executer(function () use ($a, $data): string {
                        app(Supervision::class)->justifier($a, (string) $data['motif'], auth()->user());

                        return 'Anomalie justifiée.';
                    })),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAnomaliesSysteme::route('/')];
    }
}
