<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PageResource\Pages;
use App\Models\Page;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Resource Filament `Page` (cf. spec module 1 PR K).
 *
 * Édite les pages institutionnelles `/pssfp/*` + autres pages éditoriales
 * (mentions légales, confidentialité, etc.). Body en Markdown rendu côté
 * frontend Next.js avec sanitize.
 */
class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    protected static ?string $modelLabel = 'Page';

    protected static ?string $pluralModelLabel = 'Pages';

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Module 1 — Site institutionnel';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'slug';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identité')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('slug')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(100)
                        ->helperText('Identifiant unique global utilisé dans l\'URL. Ex: pssfp/presentation'),
                    Forms\Components\TextInput::make('parent_slug')
                        ->maxLength(100)
                        ->nullable()
                        ->helperText('Slug du parent hiérarchique (ex: pssfp). Vide pour les pages racines.'),
                    Forms\Components\TextInput::make('title.fr')
                        ->required()
                        ->label('Titre (FR)')
                        ->columnSpanFull(),
                    Forms\Components\Textarea::make('excerpt.fr')
                        ->label('Résumé (FR)')
                        ->rows(2)
                        ->columnSpanFull()
                        ->helperText('Affiché dans les listes et utilisé pour le meta_description si vide.'),
                ]),

            Forms\Components\Section::make('Contenu')
                ->schema([
                    Forms\Components\Textarea::make('body.fr')
                        ->label('Corps Markdown (FR)')
                        ->required()
                        ->rows(12)
                        ->columnSpanFull()
                        ->helperText('Markdown supporté : titres ##, listes -, liens [texte](url), gras **, italique *.'),
                ]),

            Forms\Components\Section::make('SEO')
                ->columns(2)
                ->collapsed()
                ->schema([
                    Forms\Components\TextInput::make('meta_title.fr')
                        ->label('Meta title (FR)')
                        ->maxLength(70),
                    Forms\Components\TextInput::make('meta_description.fr')
                        ->label('Meta description (FR)')
                        ->maxLength(160),
                    Forms\Components\TextInput::make('og_image_path')
                        ->label('OG image (chemin MinIO)')
                        ->columnSpanFull()
                        ->helperText('Optionnel — chemin vers une image OpenGraph stockée dans pssfp-media.'),
                ]),

            Forms\Components\Section::make('Publication')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('status')
                        ->required()
                        ->options([
                            Page::STATUS_DRAFT => 'Brouillon',
                            Page::STATUS_IN_REVIEW => 'En revue',
                            Page::STATUS_PUBLISHED => 'Publiée',
                            Page::STATUS_ARCHIVED => 'Archivée',
                        ])
                        ->default(Page::STATUS_DRAFT),
                    Forms\Components\DateTimePicker::make('published_at')
                        ->native(false)
                        ->helperText('Vide = publication immédiate quand status passe à published.'),
                ]),

            Forms\Components\Section::make('Menu')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('is_in_menu')
                        ->label('Visible dans le menu principal')
                        ->default(false),
                    Forms\Components\TextInput::make('order')
                        ->numeric()
                        ->default(0)
                        ->helperText('Ordre dans le menu (croissant).'),
                    Forms\Components\TextInput::make('menu_label.fr')
                        ->label('Libellé menu (FR)')
                        ->maxLength(40)
                        ->helperText('Si vide, le titre sera utilisé.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('slug')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('title.fr')->label('Titre (FR)')->searchable()->limit(40),
                Tables\Columns\BadgeColumn::make('status')->colors([
                    'gray' => Page::STATUS_DRAFT,
                    'warning' => Page::STATUS_IN_REVIEW,
                    'success' => Page::STATUS_PUBLISHED,
                    'danger' => Page::STATUS_ARCHIVED,
                ])->sortable(),
                Tables\Columns\IconColumn::make('is_in_menu')->boolean()->label('Menu'),
                Tables\Columns\TextColumn::make('order')->sortable()->label('Ordre'),
                Tables\Columns\TextColumn::make('published_at')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    Page::STATUS_DRAFT => 'Brouillon',
                    Page::STATUS_IN_REVIEW => 'En revue',
                    Page::STATUS_PUBLISHED => 'Publiée',
                    Page::STATUS_ARCHIVED => 'Archivée',
                ]),
                Tables\Filters\TernaryFilter::make('is_in_menu')->label('Dans le menu'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->defaultSort('order');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPages::route('/'),
            'create' => Pages\CreatePage::route('/create'),
            'edit' => Pages\EditPage::route('/{record}/edit'),
        ];
    }
}
