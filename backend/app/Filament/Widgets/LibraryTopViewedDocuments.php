<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Filament\Concerns\ListsTopDocuments;
use App\Models\Document;
use App\Support\BelongsToModule;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Les 10 documents publiés les plus consultés. */
#[BelongsToModule('bibliotheque')]
class LibraryTopViewedDocuments extends TableWidget
{
    use ChecksModuleAccess;
    use ListsTopDocuments;

    protected static ?int $sort = 42;

    protected static ?string $heading = 'Documents les plus consultés';

    public static function canView(): bool
    {
        return static::moduleAllows() && (auth()->user()?->can('view_any_document') ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Document::query()->published()
                    ->where('views_count', '>', 0)
                    ->orderByDesc('views_count')->orderBy('id')
                    ->limit(10)
            )
            ->columns($this->topDocumentColumns('views_count', 'Vues'))
            ->paginated(false);
    }
}
