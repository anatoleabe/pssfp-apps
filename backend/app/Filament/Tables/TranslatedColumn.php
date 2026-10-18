<?php

declare(strict_types=1);

namespace App\Filament\Tables;

use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;

/**
 * Colonne de table pour un champ traduisible (spatie/laravel-translatable).
 *
 * `TextColumn::make('title.fr')` seul affiche une cellule vide : Filament lit
 * `$record->title`, déjà traduit en chaîne, puis cherche la clé `fr` dedans.
 * On garde le nom `{champ}.fr` (recherche JSON de Filament) et on fournit
 * l'état explicitement.
 */
final class TranslatedColumn
{
    public static function make(string $attribute, string $locale = 'fr'): TextColumn
    {
        return TextColumn::make("{$attribute}.{$locale}")
            ->getStateUsing(fn (Model $record): string => (string) $record->getTranslation($attribute, $locale, false));
    }
}
