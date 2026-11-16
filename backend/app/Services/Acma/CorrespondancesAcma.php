<?php

declare(strict_types=1);

namespace App\Services\Acma;

/** Correspondances figées entre ACMA et la plateforme (spec du lot 15, §5). */
final class CorrespondancesAcma
{
    public const PREMIERE_ANNEE = '2013-2014';

    /** Dernière année reprise dans l'archive ; 2026-2027 relève de la plateforme (décision 2). */
    public const DERNIERE_ANNEE_REPRISE = '2025-2026';

    public const ANNEE_EN_COURS = '2026-2027';

    /** Code de niveau ACMA → niveau et parcours de la plateforme (§5.2). */
    public const NIVEAUX = [
        'FPTC4' => ['niveau' => 'M1', 'parcours' => 'TC'],
        'AUCO5' => ['niveau' => 'M2', 'parcours' => 'ACFP'],
        'EPGP5' => ['niveau' => 'M2', 'parcours' => 'EPGP'],
        'FFCP5' => ['niveau' => 'M2', 'parcours' => 'FFCP'],
        'GTFP5' => ['niveau' => 'M2', 'parcours' => 'GTFP'],
        'MPPP5' => ['niveau' => 'M2', 'parcours' => 'MPP'],
    ];

    /** Filière de test d'ACMA : ses fiches ne sont jamais reprises. */
    public const FILIERE_TEST = 'POURTEST';

    /** Seuil d'acquisition d'une UE (ACMA comme la plateforme). */
    public const SEUIL = 12.0;

    /**
     * Cohortes sans décision enregistrée (§3.3, Q3) : liste fermée. Clé : année ; valeur : niveaux ACMA concernés
     * (`*` pour tous).
     */
    public const COHORTES_SANS_DECISION = [
        '2013-2014' => ['*'],
        '2014-2015' => ['*'],
        '2015-2016' => ['*'],
        '2017-2018' => ['AUCO5', 'EPGP5', 'FFCP5', 'GTFP5', 'MPPP5'],
        '2022-2023' => ['FPTC4'],
        '2024-2025' => ['FFCP5'],
        '2025-2026' => ['AUCO5', 'EPGP5', 'FFCP5', 'GTFP5', 'MPPP5'],
    ];

    /** @return array{niveau: string, parcours: string} */
    public static function niveau(string $codeAcma): array
    {
        return self::NIVEAUX[$codeAcma] ?? throw new ErreurAcma("Code de niveau ACMA inconnu : {$codeAcma}. Étape bloquée.");
    }

    public static function niveauConnu(?string $codeAcma): bool
    {
        return isset(self::NIVEAUX[(string) $codeAcma]);
    }

    /** Année reprise dans l'archive : format valide, de 2013-2014 à 2025-2026. */
    public static function anneeReprise(?string $annee): bool
    {
        $a = NormalisationAcma::annee($annee);

        return $a !== null && $a >= self::PREMIERE_ANNEE && $a <= self::DERNIERE_ANNEE_REPRISE;
    }

    public static function sansDecision(string $annee, string $niveauAcma): bool
    {
        $niveaux = self::COHORTES_SANS_DECISION[$annee] ?? [];

        return in_array('*', $niveaux, true) || in_array($niveauAcma, $niveaux, true);
    }
}
