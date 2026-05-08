<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Services\CandidatureNumeroService;
use RuntimeException;

class CandidatureObserver
{
    public function __construct(private readonly CandidatureNumeroService $numeroService) {}

    public function creating(Candidature $candidature): void
    {
        if ($candidature->numero_dossier !== null && $candidature->numero_dossier !== '') {
            return;
        }

        if ($candidature->campagne_id === null) {
            throw new RuntimeException(
                'Candidature::campagne_id is required before numero_dossier generation.'
            );
        }

        $campagne = $candidature->campagne ?? CampagneCandidature::query()
            ->findOrFail($candidature->campagne_id);

        $candidature->numero_dossier = $this->numeroService->generate($campagne);
    }
}
