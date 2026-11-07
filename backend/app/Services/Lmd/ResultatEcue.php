<?php

declare(strict_types=1);

namespace App\Services\Lmd;

/** Résultat calculé d'un ECUE : notes saisies, moyenne de la session normale et note retenue après rattrapage. */
final readonly class ResultatEcue
{
    public function __construct(
        public string $code,
        public int $credits,
        public ?float $cc,
        public ?float $assiduite,
        public ?float $examen,
        public ?string $presence,
        public ?float $moyenne,
        public bool $complete,
        public ?float $rattrapage,
        public ?float $examenRetenu,
        public ?float $moyenneRetenue,
    ) {}
}
