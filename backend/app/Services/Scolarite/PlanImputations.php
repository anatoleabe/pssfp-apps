<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use LogicException;

/**
 * Plan rendu par le planificateur : désimputations puis nouvelles imputations, dans
 * l'ordre d'écriture, et l'état du compte qui en résulte. Immuable.
 *
 * L'ordre d'écriture tient compte du déclencheur de cohérence (spec D1, §5.4) : les
 * annulations passent avant les créations, si bien que chaque instruction laisse un
 * compte cohérent.
 */
final readonly class PlanImputations
{
    /**
     * @param  list<Desimputation>  $desimputations
     * @param  list<ImputationCompte>  $imputations  prévues, sans id
     */
    public function __construct(
        public EtatCompte $initial,
        public array $desimputations,
        public array $imputations,
        public EtatCompte $etat,
    ) {}

    public static function vide(EtatCompte $etat): self
    {
        return new self($etat, [], [], $etat);
    }

    /** Impute `min(dispo, reste)` du versement sur l'inscription, si c'est positif. */
    public function imputer(int $versementId, int $inscriptionId, string $origine, ?int $creePar): self
    {
        if (! isset($this->etat->inscriptions[$inscriptionId])) {
            return $this;
        }
        $montant = min($this->etat->dispo($versementId), $this->etat->reste($inscriptionId));
        if ($montant <= 0) {
            return $this;
        }
        $imputation = new ImputationCompte(null, $versementId, $inscriptionId, $montant, $origine, $creePar);

        return new self($this->initial, $this->desimputations, [...$this->imputations, $imputation], $this->etat->avecImputation($imputation));
    }

    public function desimputer(int $imputationId, string $motif): self
    {
        $ligne = $this->etat->imputations[$imputationId] ?? throw new LogicException('Imputation absente ou déjà annulée : '.$imputationId);
        if ($this->imputations !== []) {
            throw new LogicException('Les annulations précèdent les créations dans un plan.');
        }

        return new self($this->initial, [...$this->desimputations, new Desimputation($imputationId, $ligne->montant, $motif)],
            $this->imputations, $this->etat->sansImputation($imputationId));
    }

    /** Part conservée d'une imputation annulée (R5) : même versement, inscription, origine et auteur. */
    public function remplacer(ImputationCompte $annulee, int $montant): self
    {
        $imputation = new ImputationCompte(null, $annulee->versement, $annulee->inscription, $montant, $annulee->origine, $annulee->creePar, $annulee->id);

        return new self($this->initial, $this->desimputations, [...$this->imputations, $imputation], $this->etat->avecImputation($imputation));
    }

    /** Change l'état sans rien écrire en imputation (versement annulé, remise). */
    public function avecEtat(EtatCompte $etat): self
    {
        return new self($this->initial, $this->desimputations, $this->imputations, $etat);
    }

    /** Enchaîne un plan calculé sur l'état qui résulte de celui-ci (prévisions seulement). */
    public function puis(self $suivant): self
    {
        if ($suivant->initial !== $this->etat) {
            throw new LogicException('Un plan ne s\'enchaîne qu\'à partir de l\'état qui résulte du précédent.');
        }

        return new self($this->initial, [...$this->desimputations, ...$suivant->desimputations], [...$this->imputations, ...$suivant->imputations], $suivant->etat);
    }

    public function estVide(): bool
    {
        return $this->desimputations === [] && $this->imputations === [];
    }

    public function montantImpute(): int
    {
        return array_sum(array_map(fn (ImputationCompte $x): int => $x->montant, $this->imputations));
    }

    public function montantDesimpute(): int
    {
        return array_sum(array_map(fn (Desimputation $d): int => $d->montant, $this->desimputations));
    }
}
