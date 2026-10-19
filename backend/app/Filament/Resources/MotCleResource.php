<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\RestrictedToModule;
use App\Filament\Resources\MotCleResource\Pages;
use App\Models\MotCle;
use App\Support\BelongsToModule;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Thésaurus de la bibliothèque (mots-clés hiérarchiques). */
#[BelongsToModule('bibliotheque')]
class MotCleResource extends Resource
{
    use RestrictedToModule;

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
            Forms\Components\TextInput::make('slug')->required()->unique(ignoreRecord: true)->maxLength(120)
                ->rule(DocumentResource::SLUG_RULE)->validationMessages(['regex' => 'Minuscules, chiffres et tirets simples uniquement (ex. : dette-publique).']),
            Forms\Components\Select::make('parent_id')->label('Terme parent')
                ->relationship('parent', 'slug', fn (Builder $query, ?MotCle $record) => $record ? $query->whereKeyNot($record->getKey()) : $query)
                ->searchable()->preload()
                ->rule(fn (?MotCle $record): Closure => self::parentIsNotDescendant($record)),
        ]);
    }

    /** Un terme ne peut être ni son propre parent, ni le parent de l'un de ses ancêtres. */
    private static function parentIsNotDescendant(?MotCle $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($record): void {
            if ($record === null || blank($value)) {
                return;
            }

            $seen = [];
            $current = MotCle::query()->find($value);
            while ($current !== null && ! in_array($current->getKey(), $seen, true)) {
                if ($current->is($record)) {
                    $fail('Un terme ne peut pas être son propre parent, ni celui de l\'un de ses ancêtres.');

                    return;
                }
                $seen[] = $current->getKey();
                $current = $current->parent;
            }
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('parent'))
            ->columns([
                // `label.fr` seul se lit comme une relation : état et recherche explicites.
                Tables\Columns\TextColumn::make('label.fr')->label('Libellé')
                    ->getStateUsing(fn (MotCle $record): string => $record->getTranslation('label', 'fr', false))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('label->fr', 'ilike', '%'.$search.'%')),
                Tables\Columns\TextColumn::make('parent.label')->label('Parent')
                    ->getStateUsing(fn (MotCle $record): ?string => $record->parent?->getTranslation('label', 'fr', false)),
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
