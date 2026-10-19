<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

/**
 * Ferme une Resource ou une Page à qui n'a aucun droit sur son module.
 *
 * Filament consulte `canAccess()` pour la navigation, pour chaque page d'une
 * Resource (liste, création, édition, au montage comme à chaque requête
 * Livewire) et pour la recherche globale : un seul point suffit. La Policy
 * (ou le `canAccess()` d'origine) reste appliquée ensuite, jamais assouplie.
 */
trait RestrictedToModule
{
    use ChecksModuleAccess;

    public static function canAccess(): bool
    {
        return static::moduleAllows() && parent::canAccess();
    }
}
