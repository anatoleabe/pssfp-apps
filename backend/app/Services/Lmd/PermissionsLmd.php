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

    public const NOTES_SAISIR = 'lmd.notes_saisir';

    public const NOTES_VERROUILLER = 'lmd.notes_verrouiller';

    public const NOTES_ROUVRIR = 'lmd.notes_rouvrir';

    public const NOTES_ENSEIGNANT = 'lmd.notes_enseignant';

    public const DEROGATION_RATTRAPAGE = 'lmd.derogation_rattrapage';

    public const DELIBERATION_PREPARER = 'lmd.deliberation_preparer';

    public const DELIBERATION_ARRETER = 'lmd.deliberation_arreter';

    public const DELIBERATION_PUBLIER = 'lmd.deliberation_publier';

    public const JURY_SIGNER = 'lmd.jury_signer';

    public const DOCUMENTS_REVOQUER = 'lmd.documents_revoquer';

    public const DOCUMENTS_LEVER = 'lmd.documents_lever';

    public const REQUETES_TRAITER = 'lmd.requetes_traiter';

    public const REQUETES_COTER = 'lmd.requetes_coter';

    public const REQUETES_JURY = 'lmd.requetes_jury';

    public const REQUETES_VALIDER = 'lmd.requetes_valider';

    public const ANONYMAT_GERER = 'lmd.anonymat_gerer';

    public const ANONYMAT_SAISIR = 'lmd.anonymat_saisir';

    public const ASSIDUITE = 'lmd.assiduite';

    public const MEMOIRES_SUIVRE = 'lmd.memoires_suivre';

    public const MEMOIRES_ATTESTER = 'lmd.memoires_attester';
}
