<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

/**
 * Versement valide vu par le planificateur. `reserve` : versement d'un employeur, qui ne
 * s'impute que sur son inscription désignée (décisions 2 et 7) ; `date` au format AAAA-MM-JJ.
 */
final readonly class VersementCompte
{
    public function __construct(
        public int $id,
        public string $date,
        public int $montant,
        public bool $reserve,
        public int $designee,
    ) {}

    /** Ordre chronologique (§4) : date du versement, puis ordre d'enregistrement. */
    public static function comparer(self $a, self $b): int
    {
        return [$a->date, $a->id] <=> [$b->date, $b->id];
    }
}
