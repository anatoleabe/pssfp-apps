<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

/** Inscription annuelle vue par le planificateur des imputations : barème copié et remise (spec D1, §4). */
final readonly class InscriptionCompte
{
    public function __construct(
        public int $id,
        public string $annee,
        public int $montantInscription,
        public int $montantTranche1,
        public int $montantTranche2,
        public int $remise,
    ) {}

    public function total(): int
    {
        return $this->montantInscription + $this->montantTranche1 + $this->montantTranche2;
    }

    /** Dû après remise, jamais négatif (CHECK sur inscriptions_annuelles). */
    public function du(): int
    {
        return max(0, $this->total() - $this->remise);
    }

    public function avecRemise(int $remise): self
    {
        return new self($this->id, $this->annee, $this->montantInscription, $this->montantTranche1, $this->montantTranche2, $remise);
    }
}
