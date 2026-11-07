<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\MaquetteResource\Pages;
use App\Filament\Resources\MaquetteResource\RelationManagers\UesRelationManager;
use App\Models\Maquette;
use App\Models\Parcours;
use App\Services\Lmd\ControleMaquette;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

/**
 * Parcours LMD > Maquettes : une par année et par parcours (spec module 4, lot 4).
 * Brouillon modifiable ; validée, la maquette ne bouge plus et sert de référence
 * aux inscriptions pédagogiques.
 */
#[BelongsToModule('lmd')]
class MaquetteResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = Maquette::class;

    protected static ?string $modelLabel = 'Maquette';

    protected static ?string $pluralModelLabel = 'Maquettes';

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationGroup = 'Parcours LMD';

    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form->schema(MessagesValidation::appliquer([
            Forms\Components\Section::make()->columns(2)
                ->description('La maquette est créée vide, en brouillon. Pour repartir d\'une année précédente, utilisez « Reconduire » depuis sa fiche.')
                ->schema([
                    Forms\Components\TextInput::make('annee_academique')->label('Année académique')->required()
                        ->regex('/^(\d{4})-(\d{4})$/')->placeholder('2027-2028')->maxLength(9),
                    Forms\Components\Select::make('parcours_id')->label('Parcours')->required()->native(false)
                        ->options(fn (): array => Parcours::query()->where('actif', true)->orderBy('ordre')->get()
                            ->mapWithKeys(fn (Parcours $p): array => [$p->id => $p->libelleComplet()])->all())
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Forms\Get $get): Unique => $rule->where('annee_academique', $get('annee_academique'))),
                ]),
        ], [
            'unique' => 'Ce parcours a déjà une maquette pour cette année.',
            'regex' => 'Saisissez l\'année au format 2026-2027.',
            'lte' => 'Cette valeur ne peut pas dépasser les crédits d\'un niveau.',
        ]));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['parcours', 'ues.ue', 'ues.ecues']))
            ->defaultSort('annee_academique', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('annee_academique')->label('Année')->sortable(),
                Tables\Columns\TextColumn::make('parcours.code')->label('Parcours')->fontFamily('mono'),
                Tables\Columns\TextColumn::make('parcours.libelle')->label('Libellé')->wrap(),
                Tables\Columns\TextColumn::make('parcours.niveau')->label('Niveau')->badge(),
                Tables\Columns\TextColumn::make('credits')->label('Crédits')->alignEnd()
                    ->getStateUsing(fn (Maquette $record): int => $record->totalCredits()),
                Tables\Columns\TextColumn::make('ues_count')->label('UE')->alignEnd()
                    ->getStateUsing(fn (Maquette $record): int => $record->ues->count()),
                Tables\Columns\TextColumn::make('anomalies')->label('Contrôles')->badge()
                    ->getStateUsing(fn (Maquette $record): string => self::resumeControles($record))
                    ->color(fn (Maquette $record): string => app(ControleMaquette::class)->bloquantes($record) === [] ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('statut')->label('Statut')->badge()
                    ->formatStateUsing(fn (string $state): string => Maquette::STATUTS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        Maquette::VALIDEE => 'success',
                        Maquette::BROUILLON => 'warning',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('annee_academique')->label('Année')
                    ->options(fn (): array => Maquette::query()->distinct()->orderByDesc('annee_academique')->pluck('annee_academique', 'annee_academique')->all()),
                Tables\Filters\SelectFilter::make('statut')->label('Statut')->options(Maquette::STATUTS),
            ])
            ->actions([Tables\Actions\ViewAction::make()])
            ->paginated(false);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()->columns(4)->schema([
                Infolists\Components\TextEntry::make('annee_academique')->label('Année'),
                Infolists\Components\TextEntry::make('parcours')->label('Parcours')
                    ->getStateUsing(fn (Maquette $record): string => (string) $record->parcours?->libelleComplet()),
                Infolists\Components\TextEntry::make('statut')->label('Statut')->badge()
                    ->formatStateUsing(fn (string $state): string => Maquette::STATUTS[$state] ?? $state)
                    ->color(fn (string $state): string => $state === Maquette::VALIDEE ? 'success' : ($state === Maquette::BROUILLON ? 'warning' : 'gray')),
                Infolists\Components\TextEntry::make('validee_at')->label('Validée')
                    ->getStateUsing(fn (Maquette $record): ?string => $record->validee_at === null ? null
                        : $record->validee_at->timezone('Africa/Douala')->format('d/m/Y à H:i').' par '.($record->valideePar?->name ?? '—'))
                    ->placeholder('—'),
                Infolists\Components\TextEntry::make('source')->label('Source')->placeholder('—')->columnSpanFull(),
            ]),
            Infolists\Components\Section::make('Contrôles')->schema([
                Infolists\Components\ViewEntry::make('controles')->hiddenLabel()->view('filament.lmd.controles-maquette'),
            ]),
            Infolists\Components\Section::make('Maquette')->schema([
                Infolists\Components\ViewEntry::make('maquette')->hiddenLabel()->view('filament.lmd.maquette'),
            ]),
        ]);
    }

    public static function resumeControles(Maquette $maquette): string
    {
        $anomalies = app(ControleMaquette::class)->anomalies($maquette);
        $bloquantes = count(array_filter($anomalies, fn ($a): bool => $a->estBloquante()));
        if ($anomalies === []) {
            return 'Conforme';
        }

        return $bloquantes > 0 ? $bloquantes.' bloquante(s)' : count($anomalies).' avertissement(s)';
    }

    public static function getRelations(): array
    {
        return [UesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMaquettes::route('/'),
            'create' => Pages\CreateMaquette::route('/create'),
            'view' => Pages\ViewMaquette::route('/{record}'),
        ];
    }
}
