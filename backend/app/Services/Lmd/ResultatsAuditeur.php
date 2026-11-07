<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\AcquisUe;
use App\Models\Auditeur;
use App\Models\Deliberation;
use App\Models\RegleEvaluation;
use App\Models\ResultatAnnuel;
use App\Models\ResultatScenarioUe;
use Illuminate\Support\Collection;

/**
 * Résultats d'un auditeur tels qu'il peut les voir (RG-041) : délibérations arrêtées,
 * publiées et non rectifiées seulement ; UE avec leur mode d'acquisition.
 */
final class ResultatsAuditeur
{
    public const ACQUISE = 'acquise';

    public const JURY = 'deliberation';

    public const CAPITALISEE = 'capitalisee';

    public const NON_ACQUISE = 'non_acquise';

    /** @return Collection<int, ResultatAnnuel> */
    public function publies(Auditeur $auditeur): Collection
    {
        return ResultatAnnuel::query()
            ->whereHas('inscriptionAnnuelle', fn ($q) => $q->where('auditeur_id', $auditeur->id))
            ->whereHas('deliberation', fn ($q) => $q->where('statut', Deliberation::ARRETEE)->whereNotNull('publiee_at'))
            ->with(['deliberation.parcours', 'inscriptionAnnuelle.parcours'])
            ->get()
            ->sortBy(fn (ResultatAnnuel $r): string => $r->deliberation->annee_academique.($r->deliberation->session === 'rattrapage' ? '2' : '1'))
            ->reverse()
            ->values();
    }

    /** Le résultat publié d'une délibération donnée, pour cet auditeur, ou null. */
    public function publie(Auditeur $auditeur, Deliberation $d): ?ResultatAnnuel
    {
        return $this->publies($auditeur)->first(fn (ResultatAnnuel $r): bool => $r->deliberation_id === $d->id);
    }

    /**
     * UE du résultat : code, intitulé, semestre, crédits, partie, note, grade, mode d'acquisition.
     *
     * @return list<array{code: string, intitule: string, semestre: int, credits: int, partie: string, note: ?float, grade: ?string, mode: string, annee_acquisition: ?string}>
     */
    public function ues(ResultatAnnuel $resultat): array
    {
        $d = $resultat->deliberation;
        $regle = RegleEvaluation::pourAnnee($d->annee_academique);
        $scenario = $d->scenarioRetenu()->first();
        if ($scenario === null) {
            return [];
        }
        $auditeurId = $resultat->inscriptionAnnuelle->auditeur_id;
        $acquis = AcquisUe::query()->where('auditeur_id', $auditeurId)->get()->keyBy('unite_enseignement_id');

        return ResultatScenarioUe::query()->where('scenario_id', $scenario->id)->where('inscription_annuelle_id', $resultat->inscription_annuelle_id)
            ->with('maquetteUe.ue')->get()
            ->sortBy(fn (ResultatScenarioUe $l): array => [$l->maquetteUe->semestre, $l->maquetteUe->ordre])
            ->map(function (ResultatScenarioUe $l) use ($regle, $acquis): array {
                $note = $l->note_jury === null ? null : (float) $l->note_jury;
                $mode = match (true) {
                    $l->capitalisee => self::CAPITALISEE,
                    $l->acquise && $l->mesure_id !== null => self::JURY,
                    $l->acquise => self::ACQUISE,
                    default => self::NON_ACQUISE,
                };

                return [
                    'code' => (string) $l->maquetteUe->ue?->code,
                    'intitule' => (string) $l->maquetteUe->ue?->intitule,
                    'semestre' => $l->maquetteUe->semestre,
                    'credits' => $l->credits,
                    'partie' => $l->partie,
                    'note' => $note,
                    'grade' => $note === null ? null : ($regle->grade($note)['grade'] ?? null),
                    'mode' => $mode,
                    'annee_acquisition' => $mode === self::CAPITALISEE ? $acquis->get($l->maquetteUe->unite_enseignement_id)?->annee_academique : null,
                ];
            })->values()->all();
    }
}
