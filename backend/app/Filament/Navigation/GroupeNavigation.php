<?php

declare(strict_types=1);

namespace App\Filament\Navigation;

/**
 * Groupes du menu de l'administration, rangés par métier.
 *
 * Source unique : chaque écran déclare son groupe par une de ces constantes,
 * et le panneau affiche les groupes dans l'ordre de ORDRE. Un écran qui
 * écrirait son groupe en clair créerait un groupe orphelin, en fin de menu.
 *
 * Cf. docs/plans/2026-11-09-socle-plateforme-objectifs.md, étape 1.
 */
final class GroupeNavigation
{
    public const ADMISSIONS = 'Admissions';

    public const SCOLARITE = 'Scolarité';

    public const REFERENTIEL = 'Référentiel pédagogique';

    public const PLANIFICATION = 'Planification';

    public const EVALUATIONS = 'Évaluations et jurys';

    public const ENSEIGNANTS_PAIE = 'Enseignants et paie';

    public const BIBLIOTHEQUE = 'Bibliothèque';

    public const SITE = 'Site institutionnel';

    public const ADMINISTRATION = 'Administration';

    /** Ordre d'affichage dans le menu. */
    public const ORDRE = [
        self::ADMISSIONS,
        self::SCOLARITE,
        self::REFERENTIEL,
        self::PLANIFICATION,
        self::EVALUATIONS,
        self::ENSEIGNANTS_PAIE,
        self::BIBLIOTHEQUE,
        self::SITE,
        self::ADMINISTRATION,
    ];
}
