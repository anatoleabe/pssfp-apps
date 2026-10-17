<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Document;
use App\Models\DocumentDownload;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Compteurs de la bibliothèque pour le tableau de bord (spec V1 §5.5). */
class LibraryOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 40;

    public static function canView(): bool
    {
        return auth()->user()?->can('view_any_document') ?? false;
    }

    protected function getStats(): array
    {
        return [
            Stat::make('Documents publiés', Document::query()->published()->count()),
            Stat::make('En attente de validation', Document::query()->where('status', Document::STATUS_IN_REVIEW)->count()),
            Stat::make('Téléchargements (30 j)', DocumentDownload::query()->where('downloaded_at', '>=', now()->subDays(30))->count()),
        ];
    }
}
