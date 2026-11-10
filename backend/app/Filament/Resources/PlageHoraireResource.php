<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\PlageHoraireResource\Pages;
use App\Models\PlageHoraire;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Grille horaire (spec module 4, lot 12c ; D21). */
#[BelongsToModule('lmd')]
class PlageHoraireResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = PlageHoraire::class;

    protected static ?string $modelLabel = 'Plage horaire';

    protected static ?string $pluralModelLabel = 'Plages horaires';

    protected static ?string $navigationLabel = 'Plages horaires';

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = GroupeNavigation::PLANIFICATION;

    protected static ?int $navigationSort = 110;

    public static function form(Form $form): Form
    {
        return $form->schema(MessagesValidation::appliquer([
            Forms\Components\TextInput::make('code')->label('Code')->required()->maxLength(20)->unique(ignoreRecord: true),
            Forms\Components\TextInput::make('ordre')->label('Ordre')->required()->integer()->minValue(0)->maxValue(99),
            Forms\Components\TimePicker::make('heure_debut')->label('Début')->required()->seconds(false),
            Forms\Components\TimePicker::make('heure_fin')->label('Fin')->required()->seconds(false)->after('heure_debut'),
        ], ['unique' => 'Ce code existe déjà.', 'after' => 'La fin doit suivre le début.']));
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('ordre')
            ->columns([
                Tables\Columns\TextColumn::make('ordre')->label('Ordre'),
                Tables\Columns\TextColumn::make('code')->label('Code'),
                Tables\Columns\TextColumn::make('plage')->label('Heures')->getStateUsing(fn (PlageHoraire $p): string => $p->libelle()),
            ])
            ->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManagePlagesHoraires::route('/')];
    }
}
