<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\MotCleResource\Pages;
use App\Models\MotCle;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Thésaurus de la bibliothèque (mots-clés hiérarchiques). */
class MotCleResource extends Resource
{
    protected static ?string $model = MotCle::class;

    protected static ?string $modelLabel = 'Mot-clé';

    protected static ?string $pluralModelLabel = 'Thésaurus';

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Module 3 — Bibliothèque';

    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('label.fr')->label('Libellé')->required()->maxLength(120),
            Forms\Components\TextInput::make('slug')->required()->unique(ignoreRecord: true)->maxLength(120),
            Forms\Components\Select::make('parent_id')->label('Terme parent')->relationship('parent', 'slug')->searchable()->preload(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('label.fr')->label('Libellé')->searchable(),
                Tables\Columns\TextColumn::make('parent.slug')->label('Parent'),
                Tables\Columns\TextColumn::make('documents_count')->counts('documents')->label('Documents')->sortable(),
            ])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->defaultSort('slug');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageMotsCles::route('/')];
    }
}
