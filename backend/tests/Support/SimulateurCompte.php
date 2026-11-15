<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Scolarite\EtatCompte;
use App\Services\Scolarite\InscriptionCompte;
use App\Services\Scolarite\PlanificateurImputations;
use App\Services\Scolarite\PlanImputations;
use App\Services\Scolarite\VersementCompte;

/**
 * Compte simulé en mémoire : enchaîne les règles du planificateur dans l'ordre
 * qu'applique ImputationsCompte (R2 à l'enregistrement ; R4 ou R5, puis R3).
 * Immuable : chaque événement rend un nouveau simulateur.
 */
final readonly class SimulateurCompte
{
    public function __construct(
        public EtatCompte $etat,
        public ?PlanImputations $dernierPlan = null,
        private PlanificateurImputations $planificateur = new PlanificateurImputations,
    ) {}

    public static function vide(): self
    {
        return new self(new EtatCompte([], [], []));
    }

    public function ouvrir(InscriptionCompte $inscription): self
    {
        return $this->suite($this->planificateur->avance($this->etat->avecInscription($inscription)));
    }

    public function verser(VersementCompte $versement, ?int $creePar = null): self
    {
        return $this->suite($this->planificateur->versement($this->etat->avecVersement($versement), $versement->id, $creePar));
    }

    public function annuler(int $versementId, string $motif = 'Bordereau rejeté'): self
    {
        $plan = $this->planificateur->annulation($this->etat, $versementId, $motif);

        return $this->suite($plan->puis($this->planificateur->avance($plan->etat)));
    }

    public function remise(int $inscriptionId, int $remise, string $motif = 'Bourse d\'études'): self
    {
        $plan = $this->planificateur->remise($this->etat, $inscriptionId, $remise, $motif);

        return $this->suite($plan->puis($this->planificateur->avance($plan->etat)));
    }

    /**
     * Imputations actives, dans l'ordre de création : [versement, inscription, montant, origine].
     *
     * @return list<array{0: int, 1: int, 2: int, 3: string}>
     */
    public function actives(): array
    {
        return array_values(array_map(fn ($x): array => [$x->versement, $x->inscription, $x->montant, $x->origine], $this->etat->imputations));
    }

    private function suite(PlanImputations $plan): self
    {
        return new self($plan->etat, $plan, $this->planificateur);
    }
}
