<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Support\BelongsToModule;
use App\Support\ModuleAccess;
use Filament\Pages\Dashboard as TableauDeBordFilament;

/**
 * Accueil du panneau (lot 33) : en tête du menu, avant la messagerie et les demandes. Même adresse et même nom de route
 * que le tableau de bord de Filament (`filament.admin.pages.dashboard`), qu'il remplace.
 */
#[BelongsToModule(ModuleAccess::CORE)]
class Dashboard extends TableauDeBordFilament
{
    use ChecksModuleAccess;

    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?int $navigationSort = -10;

    public static function canAccess(): bool
    {
        return static::moduleAllows();
    }

    public static function getNavigationLabel(): string
    {
        return 'Accueil';
    }

    public function getTitle(): string
    {
        return 'Accueil';
    }
}
