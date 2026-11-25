<?php

declare(strict_types=1);

namespace App\Filament\Navigation;

use App\Models\User;

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

    /** Espace du service financier (spec module-4-scolarite-espace-financier, 16/11/2026). */
    public const FINANCES = 'Finances';

    public const PLANIFICATION = 'Planification';

    public const EVALUATIONS = 'Évaluations et jurys';

    public const ENSEIGNANTS_PAIE = 'Enseignants et paie';

    public const BIBLIOTHEQUE = 'Bibliothèque';

    public const SITE = 'Site institutionnel';

    public const ADMINISTRATION = 'Administration';

    /**
     * Lot 33 (choix du Chef de projet du 25/11/2026) : le paramétrage annuel, rangé à part, replié en bas du menu ; il
     * reprend l'ancien « Référentiel pédagogique ». Les droits ne changent pas.
     */
    public const REGLAGES = 'Réglages';

    /** Ordre d'affichage dans le menu. */
    public const ORDRE = [
        self::ADMISSIONS,
        self::SCOLARITE,
        self::FINANCES,
        self::PLANIFICATION,
        self::EVALUATIONS,
        self::ENSEIGNANTS_PAIE,
        self::BIBLIOTHEQUE,
        self::SITE,
        self::ADMINISTRATION,
        self::REGLAGES,
    ];

    /**
     * Ordre du menu pour un compte : le service financier, qui encaisse sans suivre la pédagogie, voit Finances en tête.
     *
     * @return list<string>
     */
    public static function ordrePour(?User $u): array
    {
        if ($u !== null && $u->can('scolarite.recu_emettre') && ! $u->can('lmd.consulter')) {
            return [self::FINANCES, ...array_values(array_diff(self::ORDRE, [self::FINANCES]))];
        }

        return self::ORDRE;
    }
}
