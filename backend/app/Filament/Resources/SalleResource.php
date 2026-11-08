<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\SalleResource\Pages;
use App\Models\Salle;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Salles de cours (spec module 4, lot 12c ; D21). */
#[BelongsToModule('lmd')]
class SalleResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = Salle::class;

    protected static ?string $modelLabel = 'Salle';

    protected static ?string $pluralModelLabel = 'Salles';

    protected static ?string $navigationLabel = 'Salles';

    protected static ?string $navigationIcon = 'heroicon-o-building-office';

    protected static ?string $navigationGroup = 'Parcours LMD';

    protected static ?int $navigationSort = 49;

    public static function form(Form $form): Form
    {
        return $form->schema(MessagesValidation::appliquer([
            Forms\Components\TextInput::make('code')->label('Code')->required()->maxLength(20)->unique(ignoreRecord: true),
            Forms\Components\TextInput::make('libelle')->label('Libellé')->required()->maxLength(255),
            Forms\Components\Select::make('nature')->label('Nature')->required()->native(false)->options(Salle::NATURES)->default('presentiel')->live(),
            Forms\Components\TextInput::make('capacite')->label('Capacité (places)')->integer()->minValue(1)->maxValue(2000)
                ->visible(fn (Forms\Get $get): bool => $get('nature') === 'presentiel'),
            Forms\Components\TextInput::make('lien_reunion')->label('Lien de réunion permanent')->url()->maxLength(500)
                ->visible(fn (Forms\Get $get): bool => $get('nature') === 'virtuelle'),
            Forms\Components\Toggle::make('active')->label('Active')->default(true),
        ], ['unique' => 'Ce code existe déjà.', 'url' => 'Adresse attendue : https://…']));
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('code')
            ->columns([
                Tables\Columns\TextColumn::make('code')->label('Code')->searchable(),
                Tables\Columns\TextColumn::make('libelle')->label('Libellé')->searchable(),
                Tables\Columns\TextColumn::make('nature')->label('Nature')->formatStateUsing(fn (string $state): string => Salle::NATURES[$state] ?? $state),
                Tables\Columns\TextColumn::make('capacite')->label('Capacité')->placeholder('—'),
                Tables\Columns\IconColumn::make('active')->label('Active')->boolean(),
            ])
            ->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageSalles::route('/')];
    }
}
