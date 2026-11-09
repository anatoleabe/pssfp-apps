<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\ModeleActeResource\Pages;
use App\Models\EvenementCalendrier;
use App\Models\ModeleActe;
use App\Services\Lmd\Actes;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Modèles d'actes (spec module 4, lot 12b ; D18, D19) : signataire, délai, gabarit. */
#[BelongsToModule('lmd')]
class ModeleActeResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = ModeleActe::class;

    protected static ?string $modelLabel = 'Modèle d\'acte';

    protected static ?string $pluralModelLabel = 'Modèles d\'actes';

    protected static ?string $navigationLabel = 'Modèles d\'actes';

    protected static ?string $navigationIcon = 'heroicon-o-document-duplicate';

    protected static ?string $navigationGroup = GroupeNavigation::PLANIFICATION;

    protected static ?int $navigationSort = 30;

    public static function form(Form $form): Form
    {
        return $form->schema(MessagesValidation::appliquer([
            Forms\Components\TextInput::make('code')->label('Code')->required()->maxLength(40)->regex('/^[A-Z0-9_]+$/')->unique(ignoreRecord: true)
                ->disabledOn('edit'),
            Forms\Components\TextInput::make('libelle')->label('Libellé')->required()->maxLength(255),
            Forms\Components\Select::make('type_evenement')->label('Événement déclencheur')->required()
                ->options(array_diff_key(EvenementCalendrier::TYPES, ['phase' => true])),
            Forms\Components\TextInput::make('signataire')->label('Signataire')->required()->maxLength(255),
            Forms\Components\TextInput::make('delai_jours')->label('Délai (jours, négatif : avant le début de l\'événement)')->required()
                ->integer()->minValue(-365)->maxValue(365),
            Forms\Components\Toggle::make('diffusion_auditeurs')->label('Diffusé dans l\'espace des auditeurs concernés')
                ->helperText('À réserver aux communiqués : pas de liste nominative.'),
            Forms\Components\Toggle::make('actif')->label('Actif')->default(true),
            Forms\Components\Textarea::make('gabarit')->label('Gabarit')->required()->rows(8)->maxLength(10000)->columnSpanFull()
                ->helperText('Champs : '.implode(', ', array_map(fn (string $c): string => '{'.$c.'}', ModeleActe::CHAMPS)).'.'),
        ], ['regex' => 'Majuscules, chiffres et tirets bas.', 'unique' => 'Ce code existe déjà.']));
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id')
            ->columns([
                Tables\Columns\TextColumn::make('code')->label('Code')->searchable(),
                Tables\Columns\TextColumn::make('libelle')->label('Libellé')->wrap()->searchable(),
                Tables\Columns\TextColumn::make('type_evenement')->label('Événement')->formatStateUsing(fn (string $state): string => EvenementCalendrier::TYPES[$state] ?? $state),
                Tables\Columns\TextColumn::make('delai_jours')->label('Délai')->formatStateUsing(fn (ModeleActe $m): string => $m->delaiLibelle()),
                Tables\Columns\TextColumn::make('signataire')->label('Signataire'),
                Tables\Columns\IconColumn::make('diffusion_auditeurs')->label('Auditeurs')->boolean(),
                Tables\Columns\IconColumn::make('actif')->label('Actif')->boolean(),
            ])
            // Un délai modifié se reporte sur les actes non signés du modèle (spec lot 12b, §2.4).
            ->actions([Tables\Actions\EditAction::make()->after(fn (ModeleActe $record) => $record->wasChanged('delai_jours') ? app(Actes::class)->suivreModele($record) : null)]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageModelesActe::route('/'),
        ];
    }
}
