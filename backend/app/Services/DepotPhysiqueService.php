<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Candidature;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Réception des dossiers papier au bureau de la scolarité (porte 231).
 *
 * Le pointage est fait en différé par un agent : il reçoit les dossiers toute
 * la journée puis les saisit par lot le soir, ou le lendemain. La date de
 * réception est donc fournie par l'agent, jamais déduite de `now()`.
 */
final class DepotPhysiqueService
{
    public function marquer(
        Candidature $candidature,
        CarbonInterface $recuLe,
        User $agent,
        ?string $observation = null,
    ): void {
        if ($candidature->submitted_at === null) {
            throw ValidationException::withMessages([
                'depot_physique_at' => "Le dossier {$candidature->numero_dossier} n'a pas encore été soumis en ligne : "
                    ."le candidat doit d'abord valider sa candidature sur apply.pssfp.org.",
            ]);
        }

        if ($recuLe->isFuture()) {
            throw ValidationException::withMessages([
                'depot_physique_at' => 'La date de réception ne peut pas être dans le futur.',
            ]);
        }

        $candidature->update([
            'depot_physique_at' => $recuLe,
            'depot_physique_by' => $agent->id,
            'depot_physique_observation' => $observation,
        ]);

        activity('candidatures')
            ->causedBy($agent)
            ->performedOn($candidature)
            ->event('depot_physique_marked')
            ->withProperties([
                'recu_le' => $recuLe->toDateString(),
                'observation' => $observation,
            ])
            ->log('Dossier physique réceptionné');
    }

    public function annuler(Candidature $candidature, User $agent, string $motif): void
    {
        $candidature->update([
            'depot_physique_at' => null,
            'depot_physique_by' => null,
            'depot_physique_observation' => null,
        ]);

        activity('candidatures')
            ->causedBy($agent)
            ->performedOn($candidature)
            ->event('depot_physique_unmarked')
            ->withProperties(['motif' => $motif])
            ->log('Réception du dossier physique annulée');
    }
}
