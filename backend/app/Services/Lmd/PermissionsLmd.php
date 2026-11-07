<?php

declare(strict_types=1);

namespace App\Services\Lmd;

/** Permissions du module « Parcours LMD » (spec module 4, lot 4). */
final class PermissionsLmd
{
    public const CONSULTER = 'lmd.consulter';

    public const REFERENTIEL = 'lmd.referentiel';

    public const INSCRIRE = 'lmd.inscrire';

    public const VALIDER_MAQUETTE = 'lmd.maquette_valider';

    public const REGLES = 'lmd.regles';

    public const CHANGER_PARCOURS = 'lmd.parcours_changer';
}
