<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

/** Annulation prévue d'une imputation active, avec son motif (R4, R5). */
final readonly class Desimputation
{
    public function __construct(
        public int $id,
        public int $montant,
        public string $motif,
    ) {}
}
