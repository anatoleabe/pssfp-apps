<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Filament\Navigation\GroupeNavigation;
use App\Support\BelongsToModule;
use App\Support\ModuleAccess;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * « À traiter » : en tête du tableau de bord, ce qui attend l'utilisateur
 * connecté, tous modules confondus (chantier « Socle », étape 3).
 *
 * Source unique : la pastille du menu de chaque écran (ressource ou page). Un écran entre ici
 * dès qu'il définit getNavigationBadge() ; ce qu'il compte, et pour qui, se
 * décide dans l'écran (droits, périmètre de l'agent). Seuls les écrans que
 * l'utilisateur peut ouvrir sont lus.
 */
#[BelongsToModule(ModuleAccess::CORE)]
final class ATraiterWidget extends StatsOverviewWidget
{
    use ChecksModuleAccess;

    protected static ?int $sort = -10;

    protected static ?string $pollingInterval = null;

    protected ?string $heading = 'À traiter';

    public static function canView(): bool
    {
        // Socle commun : chaque écran lu filtre ensuite ses propres droits.
        return self::moduleAllows() && self::elements() !== [];
    }

    protected function getStats(): array
    {
        return array_map(
            fn (array $e): Stat => Stat::make($e['libelle'], $e['nombre'])
                ->description($e['groupe'])
                ->descriptionIcon($e['icone'])
                ->color($e['couleur'])
                ->url($e['url']),
            self::elements(),
        );
    }

    /**
     * Écrans accessibles dont la pastille signale quelque chose, dans l'ordre
     * du menu. Quelques COUNT, recalculés par canView puis getStats.
     *
     * @return list<array{libelle: string, nombre: string, groupe: string, icone: string, couleur: string, url: string, ordre: array{int, int}}>
     */
    public static function elements(): array
    {
        $elements = [];

        $panel = Filament::getPanel('admin');

        /** @var class-string<resource>|class-string<Page> $ecran */
        foreach ([...$panel->getResources(), ...$panel->getPages()] as $ecran) {
            if (! $ecran::canAccess()) {
                continue;
            }
            $nombre = $ecran::getNavigationBadge();
            if ($nombre === null) {
                continue;
            }
            $elements[] = [
                'libelle' => $ecran::getNavigationBadgeTooltip() ?? $ecran::getNavigationLabel(),
                'nombre' => $nombre,
                'groupe' => (string) $ecran::getNavigationGroup(),
                'icone' => (string) $ecran::getNavigationIcon(),
                'couleur' => is_string($couleur = $ecran::getNavigationBadgeColor()) ? $couleur : 'primary',
                'url' => is_subclass_of($ecran, Page::class) ? $ecran::getUrl() : $ecran::getUrl('index'),
                'ordre' => [(int) array_search($ecran::getNavigationGroup(), GroupeNavigation::ORDRE, true), (int) $ecran::getNavigationSort()],
            ];
        }

        usort($elements, fn (array $a, array $b): int => $a['ordre'] <=> $b['ordre']);

        return $elements;
    }
}
