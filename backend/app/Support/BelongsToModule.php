<?php

declare(strict_types=1);

namespace App\Support;

use Attribute;

/**
 * Déclare le module du back-office auquel appartient un écran Filament
 * (Resource, Page, Widget).
 *
 * `module` est une clé de ModulePermissionMap::modules() (`site`,
 * `admissions`, `bibliotheque`, `administration`…) ou ModuleAccess::CORE pour
 * le socle commun à tous les comptes du panneau (tableau de bord, profil).
 *
 * L'accès à l'écran exige alors au moins un droit sur ce module
 * (cf. ModuleAccess), **en plus** des Policies existantes. Un écran enregistré
 * dans le panneau sans cet attribut fait échouer ModuleIsolationTest.
 *
 * ```php
 * #[BelongsToModule('bibliotheque')]
 * class DocumentResource extends Resource
 * {
 *     use RestrictedToModule;
 * }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class BelongsToModule
{
    public function __construct(
        public readonly string $module,
    ) {}
}
