<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AssetResource\Pages;
use App\Models\Asset;
use App\Services\AssetImportService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Resource Filament `Asset` — bibliothèque média (cf. spec sprint S5 PR V).
 *
 * Permet aux admins de browse, upload et tagger les logos / photos / documents
 * stockés sur MinIO. Trois disks supportés : minio_media (public),
 * minio_documents (privé).
 */
class AssetResource extends Resource
{
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
                    Forms\Components\Select::make('category')
                        ->required()
                        ->options(Asset::CATEGORIES)
                        ->live()
                        ->afterStateUpdated(fn ($state, Forms\Set $set) => $set('subcategory', null)),
                    Forms\Components\Select::make('subcategory')
                        ->options(function (Forms\Get $get): array {
                            return match ($get('category')) {
                                Asset::CATEGORY_LOGO => Asset::LOGO_SUBCATEGORIES,
                                Asset::CATEGORY_PHOTO => Asset::PHOTO_SUBCATEGORIES,
                                Asset::CATEGORY_DOCUMENT => Asset::DOCUMENT_SUBCATEGORIES,
                                default => [],
                            };
                        })
                        ->required(),
                    Forms\Components\FileUpload::make('upload')
                        ->label('Fichier (upload)')
                        ->disk(fn (Forms\Get $get) => $get('category') === Asset::CATEGORY_DOCUMENT
                            ? AssetImportService::DOCUMENTS_DISK
                            : AssetImportService::MEDIA_DISK)
                        ->directory(fn (Forms\Get $get) => self::uploadDirectory((string) $get('category'), (string) $get('subcategory')))
                        ->visibility('public')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/svg+xml', 'image/webp', 'application/pdf'])
                        ->maxSize(20480)
                        ->columnSpanFull()
                        ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get): void {
                            if (! $state) {
                                return;
                            }
                            $path = is_string($state) ? $state : (method_exists($state, 'getRealPath') ? $state->getRealPath() : null);
                            if ($path === null) {
                                return;
                            }
                            $filename = basename($path);
                            $set('filename', $filename);
                            $set('original_filename', $filename);
                            $set('path', $get('category') === Asset::CATEGORY_DOCUMENT
                                ? self::uploadDirectory(Asset::CATEGORY_DOCUMENT, (string) $get('subcategory')).'/'.$filename
                                : self::uploadDirectory((string) $get('category'), (string) $get('subcategory')).'/'.$filename);
                            $set('disk', $get('category') === Asset::CATEGORY_DOCUMENT
                                ? AssetImportService::DOCUMENTS_DISK
                                : AssetImportService::MEDIA_DISK);
                        })
                        ->dehydrated(false),
                ]),

            Forms\Components\Section::make('Métadonnées')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('filename')->required()->maxLength(200),
                    Forms\Components\TextInput::make('original_filename')->maxLength(255),
                    Forms\Components\TextInput::make('disk')->required()->maxLength(30),
                    Forms\Components\TextInput::make('path')->required()->maxLength(300)->columnSpanFull(),
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
                        if (! $record->isImage()) {
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
                    ->url(fn (Asset $record): string => $record->disk === AssetImportService::MEDIA_DISK
                        ? (string) $record->publicUrl()
                        : $record->signedUrl(30))
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
