<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Support\ModuleAccess;

/**
 * Contrôle d'accès par module, pour tout écran du panneau.
 *
 * Les widgets l'appellent dans `canView()` ; les Resources et Pages passent
 * par RestrictedToModule, qui l'applique à `canAccess()` (navigation, URL,
 * recherche globale). Le module se déclare par #[BelongsToModule].
 */
trait ChecksModuleAccess
{
    protected static function moduleAllows(): bool
    {
        return ModuleAccess::allowsScreen(auth()->user(), static::class);
    }
}
