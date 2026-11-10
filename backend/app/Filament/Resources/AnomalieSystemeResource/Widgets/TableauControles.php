<?php

declare(strict_types=1);

namespace App\Filament\Resources\AnomalieSystemeResource\Widgets;

use App\Services\Supervision\Supervision;
use Filament\Widgets\Widget;

/** Tableau de bord du contrôle interne, par contrôle (lot 14a). Affiché en tête de la liste des anomalies. */
class TableauControles extends Widget
{
    protected static string $view = 'filament.supervision.tableau-controles';

    protected int|string|array $columnSpan = 'full';

    /** Affiché tout de suite : c'est la première chose que l'agent cherche sur l'écran. */
    protected static bool $isLazy = false;

    /** @return list<array<string, mixed>> */
    public function lignes(): array
    {
        return app(Supervision::class)->tableau();
    }
}
