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

    /** Libellé des types publiés avec effectifs, du plus fourni au moins fourni. */
    private function publishedByType(): array
    {
        $counts = Document::query()->published()
            ->selectRaw('type, COUNT(*) AS total')
            ->groupBy('type')
            ->pluck('total', 'type')
            ->map(fn ($n): int => (int) $n)
            ->all();
        uksort($counts, fn (string $a, string $b): int => [$counts[$b], $a] <=> [$counts[$a], $b]);

        return $counts;
    }

    protected function getStats(): array
    {
        $byType = $this->publishedByType();
        $breakdown = collect($byType)
            ->map(fn (int $n, string $type): string => (Document::TYPES[$type] ?? $type).' '.$n)
            ->implode(' · ');

        return [
            Stat::make('Documents publiés', Document::query()->published()->count()),
            Stat::make('En attente de validation', Document::query()->where('status', Document::STATUS_IN_REVIEW)->count()),
            Stat::make('Téléchargements (30 j)', DocumentDownload::query()->where('downloaded_at', '>=', now()->subDays(30))->count()),
            Stat::make('Par type', count($byType))->description($breakdown !== '' ? $breakdown : 'Aucun document publié'),
        ];
    }
}
