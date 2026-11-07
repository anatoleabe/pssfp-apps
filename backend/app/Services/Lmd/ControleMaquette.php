<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\Maquette;
use App\Models\MaquetteEcue;
use App\Models\MaquetteUe;
use App\Models\RegleEvaluation;

/**
 * Contrôles d'une maquette (spec lot 4, § 3.3). Bloquants : crédits des ECUE
 * différents de l'UE, pondérations hors 100 %, code d'ECUE en double, M2 sans
 * UE de soutenance. Avertissements : semestre hors 30 crédits, année hors 60,
 * un crédit différent de 15 heures, UE sans ECUE.
 */
final class ControleMaquette
{
    private const CREDITS_SEMESTRE = 30;

    /** @return list<Anomalie> */
    public function anomalies(Maquette $maquette): array
    {
        $maquette->loadMissing(['parcours', 'ues.ue', 'ues.ecues']);
        $anomalies = [];
        $codes = [];

        foreach ($maquette->ues as $ligne) {
            $code = $ligne->ue?->code ?? '?';
            if ($ligne->ecues->isEmpty()) {
                $anomalies[] = new Anomalie(Anomalie::AVERTISSEMENT, 'ue_sans_ecue', "L'UE {$code} n'a aucun ECUE.");
            } elseif ((int) $ligne->ecues->sum('credits') !== $ligne->credits) {
                $anomalies[] = new Anomalie(Anomalie::BLOQUANT, 'credits_ecue', sprintf(
                    "L'UE %s compte %d crédits, ses ECUE %d.", $code, $ligne->credits, (int) $ligne->ecues->sum('credits')));
            }
            if (abs((float) $ligne->pcc + (float) $ligne->ptp + (float) $ligne->pexam - 1) > 0.0001) {
                $anomalies[] = new Anomalie(Anomalie::BLOQUANT, 'ponderations', "Les pondérations de l'UE {$code} ne font pas 100 %.");
            }
            foreach ($ligne->ecues as $ecue) {
                if (isset($codes[$ecue->code])) {
                    $anomalies[] = new Anomalie(Anomalie::BLOQUANT, 'ecue_double', "Le code d'ECUE {$ecue->code} est utilisé deux fois.");
                }
                $codes[$ecue->code] = true;
                if ($ecue->credits * MaquetteEcue::HEURES_PAR_CREDIT !== $ecue->heuresTotales()) {
                    $anomalies[] = new Anomalie(Anomalie::AVERTISSEMENT, 'heures_credits', sprintf(
                        "L'ECUE %s compte %d h pour %d crédits (%d h attendues).", $ecue->code, $ecue->heuresTotales(), $ecue->credits,
                        $ecue->credits * MaquetteEcue::HEURES_PAR_CREDIT));
                }
            }
        }

        foreach ($maquette->ues->groupBy('semestre') as $semestre => $lignes) {
            $credits = (int) $lignes->sum('credits');
            if ($credits !== self::CREDITS_SEMESTRE) {
                $anomalies[] = new Anomalie(Anomalie::AVERTISSEMENT, 'credits_semestre', "Le semestre {$semestre} compte {$credits} crédits.");
            }
        }

        $attendus = RegleEvaluation::query()->where('annee_academique', $maquette->annee_academique)->value('credits_niveau') ?? 60;
        if ($maquette->totalCredits() !== (int) $attendus) {
            $anomalies[] = new Anomalie(Anomalie::AVERTISSEMENT, 'credits_annee', sprintf(
                'La maquette compte %d crédits (%d attendus).', $maquette->totalCredits(), (int) $attendus));
        }

        if ($maquette->parcours?->niveau === 'M2' && ! $maquette->ues->contains(fn (MaquetteUe $l): bool => $l->partie === MaquetteUe::SOUTENANCE)) {
            $anomalies[] = new Anomalie(Anomalie::BLOQUANT, 'sans_soutenance', 'Une maquette de M2 doit avoir une UE de soutenance (mémoire).');
        }

        return $anomalies;
    }

    /** @return list<Anomalie> */
    public function bloquantes(Maquette $maquette): array
    {
        return array_values(array_filter($this->anomalies($maquette), fn (Anomalie $a): bool => $a->estBloquante()));
    }
}
