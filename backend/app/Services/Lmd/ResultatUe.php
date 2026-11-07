<?php

declare(strict_types=1);

namespace App\Services\Lmd;

/**
 * Résultat calculé d'une UE, provisoire jusqu'à l'arrêt de la délibération (lot 6).
 *
 * @phpstan-type Ecues list<ResultatEcue>
 */
final readonly class ResultatUe
{
    /** @param  list<ResultatEcue>  $ecues */
    public function __construct(
        public string $code,
        public int $credits,
        public array $ecues,
        public ?float $noteNormale,
        public ?float $note,
        public ?float $examen,
        public bool $complete,
        public bool $eliminatoire,
        public bool $acquise,
        public bool $acquiseEnNormale,
        public bool $rattrapageSaisi,
        public bool $eligibleRattrapage,
    ) {}
}
