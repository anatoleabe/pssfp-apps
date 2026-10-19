<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Resources\LibraryRedirectResource\Pages;
use App\Models\LibraryRedirect;
use App\Support\BelongsToModule;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Anciennes adresses (redirections de l'ancien site vers la bibliothèque). */
#[BelongsToModule('bibliotheque')]
class LibraryRedirectResource extends Resource
{
    use RestrictedToModule;

    protected static ?string $model = LibraryRedirect::class;

    protected static ?string $modelLabel = 'Ancienne adresse';

    protected static ?string $pluralModelLabel = 'Anciennes adresses';

    protected static ?string $navigationIcon = 'heroicon-o-arrow-uturn-right';

    protected static ?string $navigationGroup = 'Module 3 — Bibliothèque';

    protected static ?int $navigationSort = 30;

    /** Préfixes de cible, pour le filtre par type. */
    private const TARGET_TYPES = [
        'documents' => '/documents/',
        'themes' => '/themes/',
        'collections' => '/collections/',
    ];

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('from_path')->label('Ancienne adresse')
                ->helperText('Chemin de l\'ancien site, commençant par « / » (ex. : /index.php/ressources/12-note).')
                ->required()->maxLength(500)
                ->disabledOn('edit')->dehydrated(fn (string $operation): bool => $operation === 'create')
                ->unique(ignoreRecord: true)
                ->rule(static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! str_starts_with($value, '/') || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                        $fail('L\'ancienne adresse doit commencer par « / » et ne contenir aucun caractère de contrôle.');
                    }
                }),
            Forms\Components\TextInput::make('to_path')->label('Nouvelle adresse')
                ->helperText('Chemin interne de la bibliothèque (ex. : /documents/mon-document).')
                ->required()->maxLength(500)
                ->rule(static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! LibraryRedirect::isSafeTarget($value)) {
                        $fail('Adresse cible refusée : chemin interne commençant par « / », sans « // », barre oblique inverse, caractère de contrôle ni segment « . » ou « .. ».');
                    }
                }),
            Forms\Components\Select::make('status_code')->label('Type de redirection')
                ->options([301 => '301 — permanente', 302 => '302 — temporaire'])
                ->default(301)->required()->native(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('from_path')->label('Ancienne adresse')->wrap()->searchable(),
                Tables\Columns\TextColumn::make('to_path')->label('Nouvelle adresse')->wrap()->searchable(),
                Tables\Columns\TextColumn::make('status_code')->label('Code')->badge()
                    ->color(fn (int $state): string => $state === 301 ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('hits')->label('Visites')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('updated_at')->label('Mise à jour')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type_cible')->label('Type de cible')
                    ->options([
                        'documents' => 'Documents',
                        'themes' => 'Thèmes',
                        'collections' => 'Collections',
                        'autres' => 'Autres',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if (blank($value)) {
                            return $query;
                        }
                        if ($value === 'autres') {
                            foreach (self::TARGET_TYPES as $prefix) {
                                $query->where('to_path', 'not like', self::escapeLike($prefix).'%');
                            }

                            return $query;
                        }
                        $prefix = self::TARGET_TYPES[$value] ?? null;

                        return $prefix === null ? $query : $query->where('to_path', 'like', self::escapeLike($prefix).'%');
                    }),
                Tables\Filters\Filter::make('jamais_visitee')->label('Jamais visitée')
                    ->query(fn (Builder $query): Builder => $query->where('hits', 0)),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->modalHeading('Supprimer cette ancienne adresse ?')
                    ->modalDescription('Les liens qui pointent encore vers cette adresse ne seront plus redirigés.'),
            ])
            ->defaultSort('hits', 'desc');
    }

    private static function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageLibraryRedirects::route('/')];
    }
}
