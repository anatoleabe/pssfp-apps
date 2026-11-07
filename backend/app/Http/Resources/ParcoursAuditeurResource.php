<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\InscriptionAnnuelle;
use App\Models\Maquette;
use App\Models\MaquetteEcue;
use App\Models\MaquetteUe;
use App\Services\Lmd\ParcoursAuditeur;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Parcours de l'auditeur : la maquette n'est montrée qu'une fois validée. Aucun
 * identifiant interne : les UE se désignent par leur code.
 *
 * @mixin InscriptionAnnuelle
 */
final class ParcoursAuditeurResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var InscriptionAnnuelle $inscription */
        $inscription = $this->resource;
        $parcours = app(ParcoursAuditeur::class)->pour($inscription);
        $maquette = $parcours === null ? null : Maquette::reference($inscription->annee_academique, $parcours->id);
        $maquette?->load(['ues.ue', 'ues.ecues']);

        return [
            'annee_academique' => $inscription->annee_academique,
            'niveau' => $inscription->niveau,
            'parcours' => $parcours === null ? null : ['code' => $parcours->code, 'libelle' => $parcours->libelle],
            'maquette_validee' => $maquette !== null,
            'inscrit' => $inscription->inscriptionsPedagogiques()->exists(),
            'credits_total' => $maquette?->totalCredits() ?? 0,
            'semestres' => $maquette === null ? [] : $maquette->ues->groupBy('semestre')->map(fn ($lignes, $numero): array => [
                'numero' => (int) $numero,
                'credits' => (int) $lignes->sum('credits'),
                'ues' => $lignes->map(fn (MaquetteUe $l): array => [
                    'code' => (string) $l->ue?->code,
                    'intitule' => (string) $l->ue?->intitule,
                    'credits' => $l->credits,
                    'partie' => $l->partie,
                    'heures' => $l->heures(),
                    'ecues' => $l->ecues->map(fn (MaquetteEcue $e): array => [
                        'code' => $e->code,
                        'intitule' => $e->intitule,
                        'credits' => $e->credits,
                        'heures_cm' => $e->heures_cm,
                        'heures_td' => $e->heures_td,
                        'heures_tp' => $e->heures_tp,
                        'heures_tpe' => $e->heures_tpe,
                    ])->values()->all(),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
