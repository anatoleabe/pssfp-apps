<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Forms\Components\PrivateFileUpload;
use App\Filament\Resources\AssetResource\Pages;
use App\Models\Asset;
use App\Support\BelongsToModule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Resource Filament `Asset` — bibliothèque média (cf. spec sprint S5 PR V).
 *
 * Permet aux admins de browse, upload et tagger les logos / photos / documents
 * stockés sur MinIO. Deux disques, en liste fermée selon la catégorie
 * (Asset::DISKS_BY_CATEGORY) : minio_media (public), minio_documents (privé).
 * Disque et chemin ne se saisissent pas : ils viennent du dépôt.
 */
#[BelongsToModule('site')]
class AssetResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = Asset::class;

    protected static ?string $modelLabel = 'Média';

    protected static ?string $pluralModelLabel = 'Médias';

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationGroup = 'Module 1 — Site institutionnel';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'filename';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Fichier')
                ->columns(2)
                ->schema([
                    // La catégorie fixe le disque à la création : figée ensuite.
                    Forms\Components\Select::make('category')
                        ->required()
                        ->options(Asset::CATEGORIES)
                        // Liste fermée, validée avant tout stockage du fichier déposé.
                        ->in(array_keys(Asset::CATEGORIES))
                        ->disabled(fn (string $operation): bool => $operation === 'edit')
                        ->live()
                        ->afterStateUpdated(fn ($state, Forms\Set $set, string $operation) => $operation === 'edit' ? null : $set('subcategory', null)),
                    Forms\Components\Select::make('subcategory')
                        // À l'édition, la catégorie enregistrée fait foi (champ figé).
                        ->options(fn (Forms\Get $get, ?Asset $record): array => self::subcategoriesFor($record?->category ?? $get('category')))
                        // La sous-catégorie appartient à la catégorie choisie.
                        ->in(fn (Forms\Get $get, ?Asset $record): array => array_keys(self::subcategoriesFor($record?->category ?? $get('category'))))
                        ->required(),
                    // Seule source du disque et du chemin d'un média : le dépôt, à la
                    // création. Ni disque ni chemin ne se saisissent (cf. CreateAsset).
                    PrivateFileUpload::make('upload')
                        ->label('Fichier (upload)')
                        ->required()
                        ->hiddenOn('edit')
                        ->disk(fn (Forms\Get $get): string => Asset::diskForCategory((string) $get('category')) ?? Asset::PUBLIC_DISK)
                        ->directory(fn (Forms\Get $get) => self::uploadDirectory((string) $get('category'), (string) $get('subcategory')))
                        // Documents privés (URL signée), médias publics.
                        ->visibility(fn (Forms\Get $get): string => Asset::visibilityForCategory((string) $get('category')))
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/svg+xml', 'image/webp', 'application/pdf'])
                        ->maxSize(20480)
                        ->columnSpanFull()
                        ->afterStateUpdated(function (mixed $state, Forms\Set $set): void {
                            if (! $state instanceof TemporaryUploadedFile) {
                                return;
                            }
                            $set('filename', $state->getClientOriginalName());
                            $set('original_filename', $state->getClientOriginalName());
                            $set('mime', $state->getMimeType());
                            $set('size', $state->getSize());
                        }),
                    Forms\Components\Placeholder::make('stored_file')
                        ->label('Fichier enregistré')
                        ->content(fn (?Asset $record): string => $record === null ? '' : "{$record->disk} : {$record->path}")
                        ->visibleOn('edit')
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Métadonnées')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('filename')->required()->maxLength(200),
                    Forms\Components\TextInput::make('original_filename')->maxLength(255),
                    Forms\Components\TextInput::make('mime')->required()->maxLength(100),
                    Forms\Components\TextInput::make('size')->numeric()->required(),
                    Forms\Components\TextInput::make('width')->numeric(),
                    Forms\Components\TextInput::make('height')->numeric(),
                    Forms\Components\TextInput::make('alt.fr')->label('Texte alternatif (FR)')->columnSpanFull(),
                    Forms\Components\Textarea::make('description.fr')->label('Description (FR)')->columnSpanFull(),
                    Forms\Components\TagsInput::make('tags')->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\ImageColumn::make('thumbnail')
                    ->label('Aperçu')
                    ->size(64)
                    ->getStateUsing(function (Asset $record): ?string {
                        if (! $record->isImage() || ! $record->hasAllowedDisk()) {
                            return null;
                        }
                        $path = $record->variants['thumb'] ?? $record->path;

                        return Storage::disk($record->disk)->url($path);
                    }),
                Tables\Columns\TextColumn::make('filename')->searchable()->limit(40),
                Tables\Columns\BadgeColumn::make('category')->colors([
                    'primary' => Asset::CATEGORY_PHOTO,
                    'success' => Asset::CATEGORY_LOGO,
                    'warning' => Asset::CATEGORY_DOCUMENT,
                ]),
                Tables\Columns\TextColumn::make('subcategory')->sortable(),
                Tables\Columns\TextColumn::make('tags')
                    ->getStateUsing(fn (Asset $record): string => implode(', ', $record->tags ?? []))
                    ->limit(40),
                Tables\Columns\TextColumn::make('size')
                    ->label('Poids')
                    ->formatStateUsing(fn (int $state): string => self::humanSize($state)),
                Tables\Columns\TextColumn::make('created_at')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('category')->options(Asset::CATEGORIES),
                SelectFilter::make('subcategory')->options(
                    Asset::PHOTO_SUBCATEGORIES + Asset::LOGO_SUBCATEGORIES + Asset::DOCUMENT_SUBCATEGORIES
                ),
                Tables\Filters\Filter::make('tag')
                    ->form([Forms\Components\TextInput::make('value')->label('Tag')])
                    ->query(function (Builder $query, array $data): Builder {
                        if (empty($data['value'])) {
                            return $query;
                        }

                        return $query->whereJsonContains('tags', $data['value']);
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label('Ouvrir')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    // Jamais d'URL pour un disque hors liste (ligne héritée).
                    ->visible(fn (Asset $record): bool => $record->hasAllowedDisk())
                    ->url(fn (Asset $record): ?string => $record->openUrl(30))
                    ->openUrlInNewTab(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAssets::route('/'),
            'create' => Pages\CreateAsset::route('/create'),
            'edit' => Pages\EditAsset::route('/{record}/edit'),
        ];
    }

    /** @return array<string, string> */
    private static function subcategoriesFor(mixed $category): array
    {
        return match ($category) {
            Asset::CATEGORY_LOGO => Asset::LOGO_SUBCATEGORIES,
            Asset::CATEGORY_PHOTO => Asset::PHOTO_SUBCATEGORIES,
            Asset::CATEGORY_DOCUMENT => Asset::DOCUMENT_SUBCATEGORIES,
            default => [],
        };
    }

    private static function uploadDirectory(string $category, string $subcategory): string
    {
        $sub = $subcategory !== '' ? Str::slug($subcategory) : 'misc';

        return match ($category) {
            Asset::CATEGORY_LOGO => 'logos/'.$sub,
            Asset::CATEGORY_PHOTO => 'photos/'.$sub,
            Asset::CATEGORY_DOCUMENT => 'documents/'.$sub,
            default => 'misc',
        };
    }

    private static function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return number_format($value, $i === 0 ? 0 : 1, ',', ' ').' '.$units[$i];
    }
}
