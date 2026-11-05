<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use Carbon\CarbonImmutable;

/**
 * Situation financière d'une inscription annuelle, recalculée à chaque lecture
 * et jamais stockée : elle ne peut donc pas diverger des versements.
 *
 * - La remise s'impute en partant de la fin (2e tranche, 1re, puis inscription) :
 *   une bourse partielle ne solde pas l'inscription, seule une exonération
 *   totale débloque le code d'accès sans versement.
 * - Le cumul remplit l'inscription, puis la 1re tranche, puis la 2e.
 * - L'excédent reste affiché : il n'est reporté nulle part.
 *
 * Les expressions SQL équivalentes (filtres de la liste) sont dans les scopes
 * d'InscriptionAnnuelle ; un test vérifie qu'elles donnent le même résultat.
 */
final readonly class SituationFinanciere
{
    /** @param  list<Palier>  $paliers  inscription, 1re tranche, 2e tranche */
    private function __construct(
        public array $paliers,
        public int $duTotal,
        public int $remise,
        public int $verse,
        public int $reste,
        public int $tropPercu,
    ) {}

    public static function calculer(
        int $montantInscription,
        int $montantTranche1,
        int $montantTranche2,
        int $remise,
        int $verse,
        CarbonImmutable $aujourdhui,
        ?CarbonImmutable $echeanceTranche1 = null,
        ?CarbonImmutable $echeanceTranche2 = null,
    ): self {
        $bareme = $montantInscription + $montantTranche1 + $montantTranche2;
        $remiseAppliquee = min(max(0, $remise), $bareme);

        // Remise : de la dernière tranche vers l'inscription.
        $resteRemise = $remiseAppliquee;
        $imputer = function (int $montant) use (&$resteRemise): int {
            $part = min($montant, $resteRemise);
            $resteRemise -= $part;

            return $montant - $part;
        };
        $duTranche2 = $imputer($montantTranche2);
        $duTranche1 = $imputer($montantTranche1);
        $duInscription = $imputer($montantInscription);

        // Versements : de l'inscription vers la dernière tranche.
        $disponible = max(0, $verse);
        $paliers = [];
        foreach ([
            [Palier::INSCRIPTION, $duInscription, null],
            [Palier::TRANCHE1, $duTranche1, $echeanceTranche1],
            [Palier::TRANCHE2, $duTranche2, $echeanceTranche2],
        ] as [$cle, $du, $echeance]) {
            $paye = min($du, $disponible);
            $disponible -= $paye;
            $reste = $du - $paye;
            $paliers[] = new Palier(
                cle: $cle,
                du: $du,
                paye: $paye,
                reste: $reste,
                enRetard: $reste > 0 && $echeance !== null && $echeance->startOfDay()->lessThan($aujourdhui->startOfDay()),
                echeance: $echeance,
            );
        }

        return new self(
            paliers: $paliers,
            duTotal: $bareme - $remiseAppliquee,
            remise: $remiseAppliquee,
            verse: max(0, $verse),
            reste: array_sum(array_map(fn (Palier $p): int => $p->reste, $paliers)),
            tropPercu: $disponible,
        );
    }

    /** Condition de disponibilité du code d'accès. */
    public function inscriptionSoldee(): bool
    {
        return $this->paliers[0]->solde();
    }

    public function solde(): bool
    {
        return $this->reste === 0;
    }

    public function enRetard(): bool
    {
        foreach ($this->paliers as $palier) {
            if ($palier->enRetard) {
                return true;
            }
        }

        return false;
    }

    /** Palier « Inscription » : le reste à régler pour débloquer le code. */
    public function resteInscription(): int
    {
        return $this->paliers[0]->reste;
    }
}
