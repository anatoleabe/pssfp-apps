<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

/**
 * Règles d'imputation du compte de l'auditeur (spec D1, §6 ; ADR-0013), sans base ni horloge.
 *
 * - R1 : l'argent va aux inscriptions qui ont un reste, de l'année la plus ancienne à la plus récente ;
 * - R2 : un versement libre règle ces inscriptions dans l'ordre R1, un versement d'employeur sa seule
 *   inscription désignée ; le disponible restant forme l'avance, sans ligne écrite ;
 * - R3 : l'avance s'impute en deux passes (versements réservés sur leur année, puis versements libres,
 *   le plus ancien d'abord) ;
 * - R4 : l'annulation d'un versement annule ses imputations, jamais celles d'un autre ; refusée en D1
 *   si elles portent sur plusieurs années ;
 * - R5 : une hausse de remise désimpute l'excédent en partant des imputations les plus récentes.
 *
 * Déterministe (R9) : l'ordre est total partout. Aucune division : aucun arrondi.
 * Sert à écrire (ImputationsCompte) et à prévoir sans rien écrire (avertissements des formulaires).
 */
final class PlanificateurImputations
{
    public const SAISIE = 'saisie';

    public const AVANCE = 'avance';

    public const REPRISE = 'reprise';

    public const ORIGINES = [self::SAISIE, self::AVANCE, self::REPRISE];

    /** R2 : imputations d'un versement qui vient d'être enregistré (il fait partie de l'état). */
    public function versement(EtatCompte $etat, int $versementId, ?int $creePar): PlanImputations
    {
        $v = $etat->versement($versementId);
        $plan = PlanImputations::vide($etat);
        foreach ($this->cibles($etat, $v) as $inscriptionId) {
            $plan = $plan->imputer($v->id, $inscriptionId, self::SAISIE, $creePar);
        }

        return $plan;
    }

    /** R3 : imputation de l'avance, après tout événement qui ouvre un reste ou libère du disponible. */
    public function avance(EtatCompte $etat): PlanImputations
    {
        $plan = PlanImputations::vide($etat);
        // Passe 1 : l'employeur règle d'abord l'année qu'il couvre.
        foreach ($etat->versements as $v) {
            if ($v->reserve) {
                $plan = $plan->imputer($v->id, $v->designee, self::AVANCE, null);
            }
        }
        // Passe 2 : versements libres, le plus ancien consommé le premier.
        foreach ($etat->versements as $v) {
            if (! $v->reserve) {
                foreach (array_keys($etat->inscriptions) as $inscriptionId) {
                    $plan = $plan->imputer($v->id, $inscriptionId, self::AVANCE, null);
                }
            }
        }

        return $plan;
    }

    /**
     * R4 : annule les imputations du versement et le retire du compte. R3 s'applique ensuite, sur l'état rendu.
     *
     * @throws AnnulationMultiAnnees imputé sur plusieurs années (refus R4.1 de D1)
     */
    public function annulation(EtatCompte $etat, int $versementId, string $motif): PlanImputations
    {
        $etat->versement($versementId);
        $touchees = $etat->inscriptionsTouchees($versementId);
        if (count($touchees) > 1) {
            throw new AnnulationMultiAnnees(array_map(fn (int $id): string => $etat->inscription($id)->annee, $touchees));
        }
        $plan = PlanImputations::vide($etat);
        foreach ($etat->imputationsDuVersement($versementId) as $x) {
            $plan = $plan->desimputer((int) $x->id, 'Versement annulé : '.$motif);
        }

        return $plan->avecEtat($plan->etat->sansVersement($versementId));
    }

    /** R5 : désimpute ce qui dépasse le nouveau dû, puis pose la nouvelle remise. R3 s'applique ensuite. */
    public function remise(EtatCompte $etat, int $inscriptionId, int $nouvelleRemise, string $motif): PlanImputations
    {
        $inscription = $etat->inscription($inscriptionId);
        $exces = $etat->imp($inscriptionId) - $inscription->avecRemise($nouvelleRemise)->du();
        $libelle = 'Remise portée à '.self::fcfa($nouvelleRemise).' : '.$motif;
        $plan = PlanImputations::vide($etat);
        foreach ($etat->imputationsRecentesDabord($inscriptionId) as $x) {
            if ($exces <= 0) {
                break;
            }
            $plan = $plan->desimputer((int) $x->id, $libelle);
            if ($x->montant > $exces) {
                // Annulée d'abord, puis la part conservée est recréée (ordre du déclencheur).
                $plan = $plan->remplacer($x, $x->montant - $exces);
            }
            $exces -= min($x->montant, $exces);
        }

        return $plan->avecEtat($plan->etat->avecRemise($inscriptionId, $nouvelleRemise));
    }

    /** Reprise (§10.1, étape 2) : chaque versement sur l'inscription qu'il désigne, jusqu'au dû. */
    public function reprise(EtatCompte $etat): PlanImputations
    {
        $plan = PlanImputations::vide($etat);
        foreach (array_keys($etat->inscriptions) as $inscriptionId) {
            foreach ($etat->versements as $v) {
                if ($v->designee === $inscriptionId) {
                    $plan = $plan->imputer($v->id, $inscriptionId, self::REPRISE, null);
                }
            }
        }

        return $plan;
    }

    /** @return list<int> inscriptions qu'un versement règle, dans l'ordre (§6.2) */
    private function cibles(EtatCompte $etat, VersementCompte $v): array
    {
        return $v->reserve ? [$v->designee] : array_keys($etat->inscriptions);
    }

    private static function fcfa(int $montant): string
    {
        return number_format($montant, 0, ',', ' ').' FCFA';
    }
}
