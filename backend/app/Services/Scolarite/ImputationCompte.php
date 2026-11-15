<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

/**
 * Imputation active vue par le planificateur. Dans un plan, une imputation prévue
 * (pas encore écrite) a `id` nul ; `remplace` désigne la ligne dont elle reprend la part conservée (R5).
 */
final readonly class ImputationCompte
{
    public function __construct(
        public ?int $id,
        public int $versement,
        public int $inscription,
        public int $montant,
        public string $origine,
        public ?int $creePar,
        public ?int $remplace = null,
    ) {}

    public function avecId(int $id): self
    {
        return new self($id, $this->versement, $this->inscription, $this->montant, $this->origine, $this->creePar, $this->remplace);
    }
}
