<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Filament\Concerns\ListsTopDocuments;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Support\BelongsToModule;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Les 10 documents publiés les plus téléchargés sur 30 jours (une requête groupée). */
#[BelongsToModule('bibliotheque')]
class LibraryTopDownloadedDocuments extends TableWidget
{
    use ChecksModuleAccess;
    use ListsTopDocuments;

    protected static ?int $sort = 43;

    protected static ?string $heading = 'Documents les plus téléchargés (30 jours)';

    public static function canView(): bool
    {
        return static::moduleAllows() && (auth()->user()?->can('view_any_document') ?? false);
    }

    public function table(Table $table): Table
    {
        $recent = DocumentDownload::query()
            ->selectRaw('document_id, COUNT(*) AS downloads_30d')
            ->where('downloaded_at', '>=', now()->subDays(30))
            ->groupBy('document_id');

        return $table
            ->query(
                Document::query()->published()
                    ->joinSub($recent, 'recent_downloads', 'recent_downloads.document_id', '=', 'documents.id')
                    ->select('documents.*', 'recent_downloads.downloads_30d')
                    ->orderByDesc('recent_downloads.downloads_30d')->orderBy('documents.id')
                    ->limit(10)
            )
            ->columns($this->topDocumentColumns('downloads_30d', 'Téléchargements'))
            ->paginated(false);
    }
}
