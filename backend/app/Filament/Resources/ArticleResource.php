<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ArticleResource\Pages;
use App\Models\Article;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Resource Filament `Article` (cf. spec module 1 PR N).
 *
 * Édite les actualités, billets et communiqués affichés sur /actualites/*.
 */
class ArticleResource extends Resource
{
    protected static ?string $model = Article::class;

    protected static ?string $modelLabel = 'Article';

    protected static ?string $pluralModelLabel = 'Articles';

    protected static ?string $navigationIcon = 'heroicon-o-newspaper';

    protected static ?string $navigationGroup = 'Module 1 — Site institutionnel';

    protected static ?int $navigationSort = 20;

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
                        ->maxLength(100),
                    Forms\Components\Select::make('category')
                        ->required()
                        ->options(Article::CATEGORIES),
                    Forms\Components\TextInput::make('title.fr')
                        ->required()
                        ->label('Titre (FR)')
                        ->columnSpanFull(),
                    Forms\Components\Textarea::make('excerpt.fr')
                        ->label('Résumé (FR)')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Contenu')
                ->schema([
                    Forms\Components\Textarea::make('body.fr')
                        ->label('Corps Markdown (FR)')
                        ->rows(12)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Image et publication')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('featured_image_path')
                        ->label('Chemin image vedette (MinIO)')
                        ->maxLength(200),
                    Forms\Components\Select::make('status')
                        ->required()
                        ->options([
                            Article::STATUS_DRAFT => 'Brouillon',
                            Article::STATUS_IN_REVIEW => 'En revue',
                            Article::STATUS_PUBLISHED => 'Publié',
                            Article::STATUS_ARCHIVED => 'Archivé',
                        ])
                        ->default(Article::STATUS_DRAFT),
                    Forms\Components\DateTimePicker::make('published_at')->native(false),
                    Forms\Components\Toggle::make('is_pinned')->label('Épinglé en accueil'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('slug')->searchable()->sortable()->limit(40),
                Tables\Columns\TextColumn::make('title.fr')->label('Titre (FR)')->searchable()->limit(40),
                Tables\Columns\BadgeColumn::make('category')->colors([
                    'primary' => 'evenement',
                    'success' => 'cooperation',
                    'warning' => 'vie-academique',
                    'danger' => 'communique',
                    'gray' => 'partenariat',
                ]),
                Tables\Columns\BadgeColumn::make('status')->colors([
                    'gray' => Article::STATUS_DRAFT,
                    'warning' => Article::STATUS_IN_REVIEW,
                    'success' => Article::STATUS_PUBLISHED,
                    'danger' => Article::STATUS_ARCHIVED,
                ]),
                Tables\Columns\IconColumn::make('is_pinned')->boolean()->label('Épinglé'),
                Tables\Columns\TextColumn::make('published_at')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category')->options(Article::CATEGORIES),
                Tables\Filters\SelectFilter::make('status')->options([
                    Article::STATUS_DRAFT => 'Brouillon',
                    Article::STATUS_PUBLISHED => 'Publié',
                ]),
                Tables\Filters\TernaryFilter::make('is_pinned')->label('Épinglé'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->defaultSort('published_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListArticles::route('/'),
            'create' => Pages\CreateArticle::route('/create'),
            'edit' => Pages\EditArticle::route('/{record}/edit'),
        ];
    }
}
