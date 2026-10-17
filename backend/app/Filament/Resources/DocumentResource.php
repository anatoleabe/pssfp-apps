<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\DocumentResource\Pages;
use App\Models\Document;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Documents de la bibliothèque virtuelle (spec V1 §5.5).
 *
 * Circuit : brouillon → en revue → publié (validateur tracé) → archivé.
 */
class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static ?string $modelLabel = 'Document';

    protected static ?string $pluralModelLabel = 'Documents';

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationGroup = 'Module 3 — Bibliothèque';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'slug';

    public const STATUS_LABELS = [
        Document::STATUS_DRAFT => 'Brouillon',
        Document::STATUS_IN_REVIEW => 'En revue',
        Document::STATUS_PUBLISHED => 'Publié',
        Document::STATUS_ARCHIVED => 'Archivé',
    ];

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Notice')->columns(2)->schema([
                Forms\Components\Select::make('type')->label('Type')->options(Document::TYPES)->required(),
                Forms\Components\Select::make('language')->label('Langue')->options(Document::LANGUAGES)->default('fr')->required(),
                Forms\Components\TextInput::make('title.fr')->label('Titre')->required()->maxLength(300)->columnSpanFull()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get, ?string $state) => $get('slug') ? null : $set('slug', Str::limit(Str::slug((string) $state), 150, ''))),
                Forms\Components\TextInput::make('slug')->required()->unique(ignoreRecord: true)->maxLength(160)->columnSpanFull(),
                Forms\Components\TextInput::make('subtitle.fr')->label('Sous-titre')->maxLength(300)->columnSpanFull(),
                Forms\Components\Textarea::make('abstract.fr')->label('Résumé')->rows(5)->columnSpanFull(),
                Forms\Components\TextInput::make('year')->label('Année')->numeric()->minValue(1900)->maxValue(2100),
                Forms\Components\Select::make('specialite')->label('Spécialité')->options((array) config('specialites', []))->searchable(),
                Forms\Components\TextInput::make('promotion')->label('Promotion')->numeric()->minValue(1)->maxValue(99),
                Forms\Components\TextInput::make('pages_count')->label('Pages')->numeric()->minValue(1),
                Forms\Components\TextInput::make('journal')->label('Revue')->maxLength(200),
                Forms\Components\TextInput::make('doi')->label('DOI')->maxLength(120),
                Forms\Components\TextInput::make('isbn')->label('ISBN')->maxLength(30),
            ]),
            Forms\Components\Section::make('Auteurs et mots-clés')->schema([
                Forms\Components\Repeater::make('authors')->label('Auteurs')->relationship()
                    ->orderColumn('order')
                    ->schema([
                        Forms\Components\TextInput::make('author_name')->label('Nom')->required()->maxLength(200),
                        Forms\Components\TextInput::make('author_affiliation')->label('Affiliation')->maxLength(200),
                    ])->columns(2)->defaultItems(0),
                Forms\Components\Select::make('motsCles')->label('Mots-clés')->relationship('motsCles', 'slug')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->getTranslation('label', 'fr'))
                    ->multiple()->searchable()->preload(),
            ]),
            Forms\Components\Section::make('Fichier et accès')->columns(2)->schema([
                Forms\Components\FileUpload::make('file_path')->label('PDF')
                    ->disk((string) config('library.documents_disk'))->directory('documents')->visibility('private')
                    ->acceptedFileTypes(['application/pdf'])->maxSize(204800)->columnSpanFull(),
                Forms\Components\TextInput::make('external_url')->label('Ou lien externe')->url()->maxLength(500)->columnSpanFull(),
                Forms\Components\Select::make('access_level')->label('Niveau d\'accès')->options(Document::ACCESS_LEVELS)->default('public')->required(),
                Forms\Components\TextInput::make('price_xaf')->label('Prix (FCFA)')->numeric()->minValue(1)
                    ->helperText('Non vendu tant que le paiement en ligne n\'est pas actif : le document s\'affiche « Payant — bientôt disponible ».'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title.fr')->label('Titre')->searchable()->limit(60),
                Tables\Columns\TextColumn::make('type')->label('Type')->formatStateUsing(fn (string $state) => Document::TYPES[$state] ?? $state)->badge(),
                Tables\Columns\TextColumn::make('access_mode')->label('Accès')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'payant' => 'warning', 'reserve' => 'info', default => 'success'
                    }),
                Tables\Columns\TextColumn::make('status')->label('Statut')->badge()->formatStateUsing(fn (string $state) => self::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        Document::STATUS_PUBLISHED => 'success', Document::STATUS_IN_REVIEW => 'warning', Document::STATUS_ARCHIVED => 'danger', default => 'gray'
                    }),
                Tables\Columns\TextColumn::make('year')->label('Année')->sortable(),
                Tables\Columns\TextColumn::make('views_count')->label('Vues')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('downloads_count')->label('Téléch.')->sortable()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->options(Document::TYPES),
                Tables\Filters\SelectFilter::make('status')->label('Statut')->options(self::STATUS_LABELS),
                Tables\Filters\SelectFilter::make('access_level')->label('Accès')->options(Document::ACCESS_LEVELS),
                Tables\Filters\TernaryFilter::make('legacy_source')->label('Importé de l\'ancien site')->nullable(),
                Tables\Filters\TernaryFilter::make('file_path')->label('Avec fichier')->nullable(),
            ])
            ->actions([
                Tables\Actions\Action::make('soumettre')->label('Soumettre en revue')->icon('heroicon-o-paper-airplane')
                    ->visible(fn (Document $record) => $record->status === Document::STATUS_DRAFT)
                    ->action(fn (Document $record) => $record->update(['status' => Document::STATUS_IN_REVIEW])),
                Tables\Actions\Action::make('publier')->label('Publier')->icon('heroicon-o-check-badge')->color('success')
                    ->visible(fn (Document $record) => $record->status !== Document::STATUS_PUBLISHED && auth()->user()?->can('publish', $record))
                    ->requiresConfirmation()
                    ->action(fn (Document $record) => self::publish($record)),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('publierLot')->label('Publier la sélection')->icon('heroicon-o-check-badge')
                    ->visible(fn () => auth()->user()?->can('document.publish'))
                    ->requiresConfirmation()
                    ->action(fn (Collection $records) => $records->each(fn (Document $d) => self::publish($d)))
                    ->deselectRecordsAfterCompletion(),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function publish(Document $document): void
    {
        $document->update([
            'status' => Document::STATUS_PUBLISHED,
            'published_at' => $document->published_at ?? now(),
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDocuments::route('/'),
            'create' => Pages\CreateDocument::route('/create'),
            'edit' => Pages\EditDocument::route('/{record}/edit'),
        ];
    }
}
