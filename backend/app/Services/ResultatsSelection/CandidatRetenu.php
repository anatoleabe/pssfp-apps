<?php

declare(strict_types=1);

namespace App\Services\ResultatsSelection;

/**
 * Ligne des listes nominatives du communiqué et du PV. Noms en majuscules
 * accentuées, comme sur les listes signées (« ADJIA ANICET JEAN NOËL »).
 */
final readonly class CandidatRetenu
{
    public function __construct(
        public string $nom,
        public string $prenoms,
        /** « P » (présentiel) ou « D » (distanciel). */
        public string $mode,
        /** Pays de nationalité en majuscules, ex. « TCHAD ». */
        public string $nationalite,
    ) {}

    public function nomComplet(): string
    {
        return trim($this->nom.' '.$this->prenoms);
    }
}
