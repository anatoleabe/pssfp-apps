<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\MaquetteEcue;
use App\Models\MaquetteUe;
use App\Models\NoteEcue;
use App\Models\RegleEvaluation;

/**
 * Calcul des notes (spec lot 5, § 3 ; RG-010 à RG-014 ; D10, D11, D16).
 *
 * - Moyenne d'un ECUE = CC × pcc + assiduité × ptp + examen × pexam (pondérations de l'UE).
 *   Un absent à l'examen compte 0 ; une note manquante compte 0 et rend le résultat incomplet.
 * - Note d'une UE = moyenne des ECUE pondérée par leurs crédits (règle de l'année).
 * - Rattrapage selon la règle de l'année ; un ECUE sans note de rattrapage garde sa note.
 * - UE acquise : complète, note au moins égale au seuil, sans note éliminatoire.
 */
final class CalculNotes
{
    /**
     * @param  array<int, NoteEcue>  $normales  notes de session normale, par id d'ECUE
     * @param  array<int, NoteEcue>  $rattrapages  notes de rattrapage, par id d'ECUE
     */
    public function ue(MaquetteUe $ligne, RegleEvaluation $regle, array $normales, array $rattrapages = [], bool $derogation = false): ResultatUe
    {
        $pcc = (float) $ligne->pcc;
        $ptp = (float) $ligne->ptp;
        $pexam = (float) $ligne->pexam;

        $ecues = [];
        foreach ($ligne->ecues as $ecue) {
            $ecues[] = $this->ecue($ecue, $normales[$ecue->id] ?? null, $rattrapages[$ecue->id] ?? null, $pcc, $ptp, $pexam, $regle->mode_rattrapage);
        }

        $complete = $ecues !== [] && array_reduce($ecues, fn (bool $c, ResultatEcue $e): bool => $c && $e->complete, true);
        $noteNormale = $this->moyennePonderee($ecues, fn (ResultatEcue $e): ?float => $e->moyenne);
        $note = $this->moyennePonderee($ecues, fn (ResultatEcue $e): ?float => $e->moyenneRetenue);
        $examenNormal = $this->moyennePonderee($ecues, fn (ResultatEcue $e): ?float => $e->examen ?? ($this->absent($e->presence) ? 0.0 : null));
        $examen = $this->moyennePonderee($ecues, fn (ResultatEcue $e): ?float => $e->examenRetenu);
        $rattrapageSaisi = array_filter($ecues, fn (ResultatEcue $e): bool => $e->rattrapage !== null) !== [];

        $seuil = (float) $regle->seuil_validation;
        $eliminatoireNormal = $this->eliminatoire($regle, $examenNormal, array_map(fn (ResultatEcue $e): ?float => $e->examen ?? ($this->absent($e->presence) ? 0.0 : null), $ecues));
        $eliminatoire = $this->eliminatoire($regle, $examen, array_map(fn (ResultatEcue $e): ?float => $e->examenRetenu, $ecues));
        $acquiseEnNormale = $complete && $noteNormale !== null && $noteNormale >= $seuil && ! $eliminatoireNormal;
        $acquise = $acquiseEnNormale || ($rattrapageSaisi && $note !== null && $note >= $seuil && ! $eliminatoire);

        return new ResultatUe(
            code: (string) $ligne->ue?->code,
            credits: $ligne->credits,
            ecues: $ecues,
            noteNormale: $noteNormale,
            note: $note,
            examen: $examen,
            complete: $complete,
            eliminatoire: $eliminatoire,
            acquise: $acquise,
            acquiseEnNormale: $acquiseEnNormale,
            rattrapageSaisi: $rattrapageSaisi,
            eligibleRattrapage: ! $acquiseEnNormale && ($complete || $derogation),
        );
    }

    private function ecue(MaquetteEcue $ecue, ?NoteEcue $n, ?NoteEcue $r, float $pcc, float $ptp, float $pexam, string $mode): ResultatEcue
    {
        $cc = $n?->valeur('cc');
        $ass = $n?->valeur('assiduite');
        $presence = $n?->presence_examen;
        $exam = $n?->valeur('examen');
        $examCalcul = $exam ?? ($this->absent($presence) ? 0.0 : null);

        $complete = ($pcc <= 0 || $cc !== null) && ($ptp <= 0 || $ass !== null) && ($pexam <= 0 || $examCalcul !== null);
        $moyenne = $n === null ? null : $this->moyenne($cc, $ass, $examCalcul, $pcc, $ptp, $pexam);

        $rat = $r?->valeur('examen') ?? ($r !== null && $this->absent($r->presence_examen) ? 0.0 : null);
        $examenRetenu = $examCalcul;
        $moyenneRetenue = $moyenne;
        if ($rat !== null) {
            [$examenRetenu, $moyenneRetenue] = match ($mode) {
                RegleEvaluation::RATTRAPAGE_REMPLACE_MOYENNE => [$rat, round($rat, 3)],
                RegleEvaluation::RATTRAPAGE_REMPLACE_EXAMEN => [$rat, $this->moyenne($cc, $ass, $rat, $pcc, $ptp, $pexam)],
                default => [max($rat, (float) $examCalcul), max((float) $moyenne, $this->moyenne($cc, $ass, $rat, $pcc, $ptp, $pexam))],
            };
        }

        return new ResultatEcue($ecue->code, $ecue->credits, $cc, $ass, $exam, $presence, $moyenne, $complete, $rat, $examenRetenu, $moyenneRetenue);
    }

    private function moyenne(?float $cc, ?float $ass, ?float $exam, float $pcc, float $ptp, float $pexam): float
    {
        return round(($cc ?? 0) * $pcc + ($ass ?? 0) * $ptp + ($exam ?? 0) * $pexam, 3);
    }

    /**
     * Moyenne pondérée par les crédits ; à crédits nuls, moyenne simple. Null si aucune valeur.
     *
     * @param  list<ResultatEcue>  $ecues
     * @param  callable(ResultatEcue): ?float  $valeur
     */
    private function moyennePonderee(array $ecues, callable $valeur): ?float
    {
        $somme = 0.0;
        $poids = 0;
        $simple = [];
        foreach ($ecues as $e) {
            $v = $valeur($e);
            if ($v === null) {
                continue;
            }
            $somme += $v * $e->credits;
            $poids += $e->credits;
            $simple[] = $v;
        }
        if ($simple === []) {
            return null;
        }

        return round($poids > 0 ? $somme / $poids : array_sum($simple) / count($simple), 3);
    }

    /** @param  list<?float>  $examensEcue */
    private function eliminatoire(RegleEvaluation $regle, ?float $examenUe, array $examensEcue): bool
    {
        if ($regle->note_plancher === null) {
            return false;
        }
        $plancher = (float) $regle->note_plancher;
        if ($regle->note_plancher_porte === RegleEvaluation::PLANCHER_EXAMEN_ECUE) {
            return array_filter($examensEcue, fn (?float $x): bool => $x !== null && $x < $plancher) !== [];
        }

        return $examenUe !== null && $examenUe < $plancher;
    }

    private function absent(?string $presence): bool
    {
        return $presence === NoteEcue::ABSENT_JUSTIFIE || $presence === NoteEcue::ABSENT_NON_JUSTIFIE;
    }
}
