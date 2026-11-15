<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use Carbon\CarbonImmutable;
use LogicException;

/**
 * Compte d'un auditeur à un instant donné, sans base ni horloge (spec D1, §4 et §7.1) :
 * ses inscriptions (par année croissante), ses versements valides (ordre chronologique)
 * et leurs imputations actives (par id). Immuable : chaque modification rend un nouvel état.
 *
 * Avance = versements valides − imputations actives ; elle n'est jamais stockée.
 */
final readonly class EtatCompte
{
    /** @var array<int, InscriptionCompte> par année croissante */
    public array $inscriptions;

    /** @var array<int, VersementCompte> versements valides, ordre chronologique */
    public array $versements;

    /** @var array<int, ImputationCompte> imputations actives, par id croissant */
    public array $imputations;

    /** Identifiant donné à la prochaine imputation prévue : toujours après les actives, comme en base. */
    public int $prochainId;

    /**
     * @param  list<InscriptionCompte>  $inscriptions
     * @param  list<VersementCompte>  $versements
     * @param  list<ImputationCompte>  $imputations  avec leur id
     */
    public function __construct(array $inscriptions, array $versements, array $imputations, ?int $prochainId = null)
    {
        usort($inscriptions, fn (InscriptionCompte $a, InscriptionCompte $b): int => [$a->annee, $a->id] <=> [$b->annee, $b->id]);
        usort($versements, VersementCompte::comparer(...));
        usort($imputations, fn (ImputationCompte $a, ImputationCompte $b): int => $a->id <=> $b->id);

        $this->inscriptions = array_column(array_map(fn (InscriptionCompte $i): array => [$i->id, $i], $inscriptions), 1, 0);
        $this->versements = array_column(array_map(fn (VersementCompte $v): array => [$v->id, $v], $versements), 1, 0);
        $this->imputations = array_column(array_map(function (ImputationCompte $x): array {
            if ($x->id === null) {
                throw new LogicException('Une imputation active a toujours un identifiant.');
            }

            return [$x->id, $x];
        }, $imputations), 1, 0);
        $this->prochainId = max($prochainId ?? 1, $this->imputations === [] ? 1 : max(array_keys($this->imputations)) + 1);
    }

    public function inscription(int $id): InscriptionCompte
    {
        return $this->inscriptions[$id] ?? throw new LogicException('Inscription absente du compte : '.$id);
    }

    public function versement(int $id): VersementCompte
    {
        return $this->versements[$id] ?? throw new LogicException('Versement absent du compte ou annulé : '.$id);
    }

    /** Imputé d'une inscription : somme de ses imputations actives. */
    public function imp(int $inscriptionId): int
    {
        $somme = 0;
        foreach ($this->imputations as $x) {
            $somme += $x->inscription === $inscriptionId ? $x->montant : 0;
        }

        return $somme;
    }

    public function reste(int $inscriptionId): int
    {
        return $this->inscription($inscriptionId)->du() - $this->imp($inscriptionId);
    }

    /** Somme des imputations actives d'un versement. */
    public function impute(int $versementId): int
    {
        $somme = 0;
        foreach ($this->imputations as $x) {
            $somme += $x->versement === $versementId ? $x->montant : 0;
        }

        return $somme;
    }

    public function dispo(int $versementId): int
    {
        return isset($this->versements[$versementId]) ? $this->versements[$versementId]->montant - $this->impute($versementId) : 0;
    }

    public function avance(): int
    {
        return $this->avanceLibre() + $this->avanceReservee();
    }

    public function avanceLibre(): int
    {
        return $this->avanceDe(false);
    }

    public function avanceReservee(): int
    {
        return $this->avanceDe(true);
    }

    /** Inscription de l'année la plus récente : celle qui commande l'accès. */
    public function courante(): ?InscriptionCompte
    {
        $dernier = array_key_last($this->inscriptions);

        return $dernier === null ? null : $this->inscriptions[$dernier];
    }

    /** Restes des inscriptions antérieures à la courante. */
    public function arrieres(): int
    {
        $courante = $this->courante();

        return $this->resteParcours() - ($courante === null ? 0 : $this->reste($courante->id));
    }

    public function resteParcours(): int
    {
        $somme = 0;
        foreach ($this->inscriptions as $i) {
            $somme += $this->reste($i->id);
        }

        return $somme;
    }

    /** @return list<int> inscriptions touchées par les imputations actives du versement, par année croissante */
    public function inscriptionsTouchees(int $versementId): array
    {
        $ids = [];
        foreach ($this->imputations as $x) {
            if ($x->versement === $versementId) {
                $ids[$x->inscription] = true;
            }
        }

        return array_values(array_filter(array_keys($this->inscriptions), fn (int $id): bool => isset($ids[$id])));
    }

    /** @return list<ImputationCompte> */
    public function imputationsDuVersement(int $versementId): array
    {
        return array_values(array_filter($this->imputations, fn (ImputationCompte $x): bool => $x->versement === $versementId));
    }

    /**
     * Imputations actives d'une inscription, les plus récentes d'abord (R5) : versement le plus
     * récent (date puis id décroissants), puis id de l'imputation décroissant.
     *
     * @return list<ImputationCompte>
     */
    public function imputationsRecentesDabord(int $inscriptionId): array
    {
        $lignes = array_values(array_filter($this->imputations, fn (ImputationCompte $x): bool => $x->inscription === $inscriptionId));
        usort($lignes, function (ImputationCompte $a, ImputationCompte $b): int {
            $va = $this->versement($a->versement);
            $vb = $this->versement($b->versement);

            return [$vb->date, $vb->id, $b->id] <=> [$va->date, $va->id, $a->id];
        });

        return $lignes;
    }

    /** Droits d'inscription soldés (palier « inscription » payé) : la condition d'accès. */
    public function droitsSoldes(int $inscriptionId): bool
    {
        $i = $this->inscription($inscriptionId);

        // La date du jour ne sert qu'aux retards, sans effet sur ce palier.
        return SituationFinanciere::calculer($i->montantInscription, $i->montantTranche1, $i->montantTranche2, $i->remise,
            $this->imp($inscriptionId), CarbonImmutable::create(2000, 1, 1))->inscriptionSoldee();
    }

    public function avecInscription(InscriptionCompte $inscription): self
    {
        return new self([...array_values($this->inscriptions), $inscription], array_values($this->versements), array_values($this->imputations), $this->prochainId);
    }

    public function avecVersement(VersementCompte $versement): self
    {
        return new self(array_values($this->inscriptions), [...array_values($this->versements), $versement], array_values($this->imputations), $this->prochainId);
    }

    public function sansVersement(int $versementId): self
    {
        if ($this->impute($versementId) > 0) {
            throw new LogicException('Un versement annulé ne garde aucune imputation active.');
        }
        $versements = $this->versements;
        unset($versements[$versementId]);

        return new self(array_values($this->inscriptions), array_values($versements), array_values($this->imputations), $this->prochainId);
    }

    public function avecRemise(int $inscriptionId, int $remise): self
    {
        $inscriptions = $this->inscriptions;
        $inscriptions[$inscriptionId] = $this->inscription($inscriptionId)->avecRemise($remise);

        return new self(array_values($inscriptions), array_values($this->versements), array_values($this->imputations), $this->prochainId);
    }

    /** Ajoute une imputation prévue sous l'identifiant suivant. */
    public function avecImputation(ImputationCompte $imputation): self
    {
        return new self(array_values($this->inscriptions), array_values($this->versements),
            [...array_values($this->imputations), $imputation->avecId($this->prochainId)], $this->prochainId + 1);
    }

    public function sansImputation(int $imputationId): self
    {
        $imputations = $this->imputations;
        unset($imputations[$imputationId]);

        return new self(array_values($this->inscriptions), array_values($this->versements), array_values($imputations), $this->prochainId);
    }

    private function avanceDe(bool $reserve): int
    {
        $somme = 0;
        foreach ($this->versements as $v) {
            $somme += $v->reserve === $reserve ? $this->dispo($v->id) : 0;
        }

        return $somme;
    }
}
