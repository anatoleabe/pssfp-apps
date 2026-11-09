<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\ParcoursResource\Pages;
use App\Models\Parcours;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Parcours LMD : tronc commun du M1 et options du M2, reliés à la spécialité du dossier de candidature. */
#[BelongsToModule('lmd')]
class ParcoursResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = Parcours::class;

    protected static ?string $modelLabel = 'Parcours';

    protected static ?string $pluralModelLabel = 'Parcours';

    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationGroup = GroupeNavigation::REFERENTIEL;

    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form->schema(MessagesValidation::appliquer([
            Forms\Components\Section::make()->columns(2)->schema([
                Forms\Components\TextInput::make('code')->label('Code')->required()->maxLength(10)
                    ->regex('/^[A-Z0-9]+$/')->helperText('Majuscules et chiffres, par exemple FFCP.')
                    ->unique(ignoreRecord: true)->disabledOn('edit'),
                Forms\Components\Select::make('niveau')->label('Niveau')->required()->native(false)
                    ->options(['M1' => 'Master 1', 'M2' => 'Master 2'])->disabledOn('edit'),
                Forms\Components\TextInput::make('libelle')->label('Libellé')->required()->maxLength(255)->columnSpanFull(),
                Forms\Components\Select::make('specialite')->label('Spécialité du dossier de candidature')->native(false)
                    ->options(fn (): array => collect((array) config('specialites'))->mapWithKeys(fn (string $l): array => [$l => $l])->all())
                    ->helperText('En M2, l\'auditeur est inscrit sur l\'option de sa spécialité.')->columnSpanFull(),
                Forms\Components\TextInput::make('ordre')->label('Ordre d\'affichage')->integer()->minValue(0)->default(0),
                Forms\Components\Toggle::make('actif')->label('Actif')->default(true)->inline(false),
            ]),
        ], [
            'unique' => 'Ce code de parcours existe déjà.',
            'regex' => 'Majuscules et chiffres seulement, par exemple FFCP.',
            'lte' => 'Cette valeur ne peut pas dépasser les crédits d\'un niveau.',
        ]));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('ordre')
            ->columns([
                Tables\Columns\TextColumn::make('code')->label('Code')->fontFamily('mono')->sortable(),
                Tables\Columns\TextColumn::make('niveau')->label('Niveau')->badge()->sortable(),
                Tables\Columns\TextColumn::make('libelle')->label('Libellé')->wrap(),
                Tables\Columns\TextColumn::make('specialite')->label('Spécialité du dossier')->placeholder('—')->wrap(),
                Tables\Columns\IconColumn::make('actif')->label('Actif')->boolean(),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->paginated(false);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListParcours::route('/'),
            'create' => Pages\CreateParcours::route('/create'),
            'edit' => Pages\EditParcours::route('/{record}/edit'),
        ];
    }
}
