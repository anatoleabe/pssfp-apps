<?php

declare(strict_types=1);

namespace App\Services\Acma;

/**
 * Comparaison d'un dossier d'archive et d'un auditeur de la plateforme (spec du lot 15, §6.3). Classe pure.
 *
 * Seuls R1 et R2 lient d'office. Un lien à tort mettrait les notes d'une personne sur la fiche d'une autre : tout autre
 * cas s'arbitre.
 */
final class ComparaisonIdentite
{
    /** Score minimal de deux noms « compatibles » : jetons communs / plus petit des deux ensembles. */
    public const SEUIL = 0.66;

    public const LIENS_AUTOMATIQUES = ['R1', 'R2'];

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    public static function score(array $a, array $b): float
    {
        $plusPetit = min(count($a), count($b));

        return $plusPetit === 0 ? 0.0 : round(count(array_intersect($a, $b)) / $plusPetit, 3);
    }

    public static function compatibles(float $score): bool
    {
        return $score >= self::SEUIL;
    }

    /**
     * Cas d'un dossier et d'un auditeur au même matricule : R1 à R4. $dateArchive est null quand ACMA n'a pas de date
     * exploitable (vide, aberrante ou « né vers »).
     */
    public static function memeMatricule(?string $dateArchive, ?string $datePlateforme, float $score): string
    {
        if ($dateArchive !== null && $datePlateforme !== null && $dateArchive !== $datePlateforme) {
            return 'R4';
        }
        if (! self::compatibles($score)) {
            return 'R3';
        }

        return $dateArchive === null ? 'R2' : 'R1';
    }

    /** R6 : autre matricule, même date de naissance, noms identiques (score 1). */
    public static function autreMatricule(?string $dateArchive, ?string $datePlateforme, float $score): bool
    {
        return $dateArchive !== null && $dateArchive === $datePlateforme && $score >= 1.0;
    }

    public static function motif(string $cas): string
    {
        return match ($cas) {
            'R1' => 'Lien automatique : matricule, date de naissance et noms concordants',
            'R2' => 'Lien automatique : matricule et noms concordants, date ACMA inexploitable',
            'R3' => 'À arbitrer : même matricule et même date, noms non compatibles',
            'R4' => 'À arbitrer : même matricule, dates de naissance différentes',
            'R6' => 'À arbitrer : même date et mêmes noms sous un autre matricule',
            default => 'Rapprochement',
        };
    }
}
