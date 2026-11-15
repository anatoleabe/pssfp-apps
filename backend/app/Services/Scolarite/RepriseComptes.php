<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\Versement;

/**
 * Reprise des versements existants dans le compte de l'auditeur (spec D1, §10.1).
 * Appelée par la migration 2026_12_01_100100 seulement. Idempotente : un auditeur qui
 * a déjà une imputation est ignoré.
 *
 * Pour chaque auditeur qui a un versement, par id croissant et sous son verrou :
 * chaque versement valide s'impute sur l'inscription qu'il désigne, dans l'ordre
 * chronologique, jusqu'au dû (origine `reprise`) ; l'excédent devient avance, sans
 * ligne ; puis R3 (origine `avance`, sans effet pour un auditeur à une inscription).
 * Aucun SMS, aucun e-mail, aucun reçu : les reçus émis restent tels quels.
 */
final class RepriseComptes
{
    public function __construct(private readonly ImputationsCompte $imputations) {}

    /**
     * @return array{auditeurs_traites: int, auditeurs_ignores: int, imputations: int, montant_impute: int, avance_totale: int,
     *               avance_reservee: int, auditeurs_avec_avance: int, imputations_avance: int, duree_ms: int}
     */
    public function executer(): array
    {
        $debut = hrtime(true);
        $bilan = [
            'auditeurs_traites' => 0, 'auditeurs_ignores' => 0, 'imputations' => 0, 'montant_impute' => 0,
            'avance_totale' => 0, 'avance_reservee' => 0, 'auditeurs_avec_avance' => 0, 'imputations_avance' => 0, 'duree_ms' => 0,
        ];

        $auditeurs = Versement::query()->select('auditeur_id')->distinct()->orderBy('auditeur_id')->pluck('auditeur_id');
        foreach ($auditeurs as $auditeurId) {
            $plans = $this->imputations->sousVerrou((int) $auditeurId, fn (ImputationsCompte $compte): ?array => $compte->reprendre((int) $auditeurId));
            if ($plans === null) {
                $bilan['auditeurs_ignores']++;

                continue;
            }
            $etat = $plans['avance']->etat;
            $bilan['auditeurs_traites']++;
            $bilan['imputations'] += count($plans['reprise']->imputations) + count($plans['avance']->imputations);
            $bilan['montant_impute'] += $plans['reprise']->montantImpute() + $plans['avance']->montantImpute();
            $bilan['imputations_avance'] += count($plans['avance']->imputations);
            $bilan['avance_totale'] += $etat->avance();
            $bilan['avance_reservee'] += $etat->avanceReservee();
            $bilan['auditeurs_avec_avance'] += $etat->avance() > 0 ? 1 : 0;
        }
        $bilan['duree_ms'] = (int) ((hrtime(true) - $debut) / 1_000_000);

        return $bilan;
    }
}
