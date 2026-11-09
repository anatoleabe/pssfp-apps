<?php

declare(strict_types=1);

use App\Filament\Navigation\GroupeNavigation;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;

uses()->group('filament');

/**
 * Écrans du panneau qui apparaissent dans le menu (le tableau de bord, hors
 * groupe, est exclu).
 *
 * @return list<class-string>
 */
function ecransDuMenu(): array
{
    $panel = Filament::getPanel('admin');

    return array_values(array_filter(
        [...$panel->getResources(), ...$panel->getPages()],
        static fn (string $ecran): bool => $ecran::getNavigationGroup() !== null,
    ));
}

it('range chaque écran dans un groupe métier connu', function (): void {
    $horsListe = array_filter(
        ecransDuMenu(),
        static fn (string $ecran): bool => ! in_array($ecran::getNavigationGroup(), GroupeNavigation::ORDRE, true),
    );

    expect($horsListe)->toBe([]);
});

it('affiche les groupes dans l\'ordre métier', function (): void {
    $groupes = array_map(
        static fn (NavigationGroup $groupe): string => (string) $groupe->getLabel(),
        array_values(Filament::getPanel('admin')->getNavigationGroups()),
    );

    expect($groupes)->toBe(GroupeNavigation::ORDRE);
});

it('ne laisse aucun groupe vide', function (): void {
    $utilises = array_unique(array_map(
        static fn (string $ecran): string => $ecran::getNavigationGroup(),
        ecransDuMenu(),
    ));

    expect(array_values(array_diff(GroupeNavigation::ORDRE, $utilises)))->toBe([]);
});
