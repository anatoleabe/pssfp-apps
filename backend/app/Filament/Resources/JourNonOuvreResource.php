<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Resources\AuditeurResource\MessagesValidation;
use App\Filament\Resources\JourNonOuvreResource\Pages;
use App\Models\JourNonOuvre;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Jours non ouvrés (spec module 4, lot 12a ; D21) : ni séance ni examen. */
#[BelongsToModule('lmd')]
class JourNonOuvreResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = JourNonOuvre::class;

    protected static ?string $modelLabel = 'Jour non ouvré';

    protected static ?string $pluralModelLabel = 'Jours non ouvrés';

    protected static ?string $navigationLabel = 'Jours non ouvrés';

    protected static ?string $navigationIcon = 'heroicon-o-no-symbol';

    protected static ?string $navigationGroup = 'Parcours LMD';

    protected static ?int $navigationSort = 48;

    public static function form(Form $form): Form
    {
        return $form->schema(MessagesValidation::appliquer([
            Forms\Components\DatePicker::make('jour')->label('Jour')->required()->native(false)->displayFormat('d/m/Y')->unique(ignoreRecord: true),
            Forms\Components\TextInput::make('libelle')->label('Libellé')->required()->maxLength(255),
        ], ['unique' => 'Ce jour est déjà déclaré non ouvré.']));
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('jour')
            ->columns([
                Tables\Columns\TextColumn::make('jour')->label('Jour')->date('l d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('libelle')->label('Libellé')->searchable(),
            ])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageJoursNonOuvres::route('/'),
        ];
    }
}
