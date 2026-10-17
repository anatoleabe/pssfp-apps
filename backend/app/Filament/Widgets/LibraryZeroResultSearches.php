<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\SearchLog;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Les 10 recherches sans résultat les plus fréquentes sur 30 jours : pistes d'enrichissement du fonds. */
class LibraryZeroResultSearches extends TableWidget
{
    protected static ?int $sort = 41;

    protected static ?string $heading = 'Recherches sans résultat (30 jours)';

    public static function canView(): bool
    {
        return auth()->user()?->can('view_any_document') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                SearchLog::query()
                    ->selectRaw('MIN(id) AS id, query, COUNT(*) AS total')
                    ->where('results_count', 0)
                    ->where('searched_at', '>=', now()->subDays(30))
                    ->groupBy('query')
                    ->orderByDesc('total')
                    ->limit(10)
            )
            ->columns([
                Tables\Columns\TextColumn::make('query')->label('Recherche'),
                Tables\Columns\TextColumn::make('total')->label('Occurrences'),
            ])
            ->paginated(false);
    }
}
