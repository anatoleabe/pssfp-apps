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
 * - L'excédent (tropPercu) est affiché comme une avance : il n'est reporté sur
 *   aucun palier.
 *
 * - Confirmation par la scolarité (15/11/2026) : les montants (versé, payé par
 *   palier, reste) lisent tout l'argent imputé, comme le compte (D1) et le reçu ;
 *   les états (inscription soldée, soldé, en retard) ne lisent que l'imputé des
 *   versements confirmés. `aConfirmer` est la part encore à confirmer.
 *
 * Les expressions SQL équivalentes (filtres de la liste) sont dans les scopes
 * d'InscriptionAnnuelle ; un test vérifie qu'elles donnent le même résultat.
 */
final readonly class SituationFinanciere
{
    /**
     * @param  list<Palier>  $paliers  inscription, 1re tranche, 2e tranche, sur tout l'argent imputé
     * @param  list<Palier>  $paliersConfirmes  les mêmes, sur l'imputé confirmé seul : source des états
     */
    private function __construct(
        public array $paliers,
        public int $duTotal,
        public int $remise,
        public int $verse,
        public int $reste,
        public int $tropPercu,
        public int $aConfirmer,
        private array $paliersConfirmes,
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
        int $aConfirmer = 0,
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

        $dus = [
            [Palier::INSCRIPTION, $duInscription, null],
            [Palier::TRANCHE1, $duTranche1, $echeanceTranche1],
            [Palier::TRANCHE2, $duTranche2, $echeanceTranche2],
        ];
        $aConfirmer = min(max(0, $aConfirmer), max(0, $verse));
        [$paliers, $disponible] = self::remplir($dus, max(0, $verse), $aujourdhui);
        [$paliersConfirmes] = $aConfirmer === 0 ? [$paliers] : self::remplir($dus, max(0, $verse) - $aConfirmer, $aujourdhui);

        return new self(
            paliers: $paliers,
            duTotal: $bareme - $remiseAppliquee,
            remise: $remiseAppliquee,
            verse: max(0, $verse),
            reste: array_sum(array_map(fn (Palier $p): int => $p->reste, $paliers)),
            tropPercu: $disponible,
            aConfirmer: $aConfirmer,
            paliersConfirmes: $paliersConfirmes,
        );
    }

    /**
     * Versements : de l'inscription vers la dernière tranche.
     *
     * @param  list<array{string, int, ?CarbonImmutable}>  $dus
     * @return array{list<Palier>, int} paliers, et ce qui dépasse le dû
     */
    private static function remplir(array $dus, int $verse, CarbonImmutable $aujourdhui): array
    {
        $disponible = $verse;
        $paliers = [];
        foreach ($dus as [$cle, $du, $echeance]) {
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

        return [$paliers, $disponible];
    }

    /** Condition de disponibilité du code d'accès : sur l'argent confirmé seul. */
    public function inscriptionSoldee(): bool
    {
        return $this->paliersConfirmes[0]->solde();
    }

    /** Soldé : sur l'argent confirmé seul. */
    public function solde(): bool
    {
        foreach ($this->paliersConfirmes as $palier) {
            if (! $palier->solde()) {
                return false;
            }
        }

        return true;
    }

    /** En retard : sur l'argent confirmé seul. */
    public function enRetard(): bool
    {
        foreach ($this->paliersConfirmes as $palier) {
            if ($palier->enRetard) {
                return true;
            }
        }

        return false;
    }

    /** Imputé des versements confirmés par la scolarité. */
    public function verseConfirme(): int
    {
        return $this->verse - $this->aConfirmer;
    }

    /** Palier « Inscription » : le reste à régler pour débloquer le code. */
    public function resteInscription(): int
    {
        return $this->paliers[0]->reste;
    }
}
