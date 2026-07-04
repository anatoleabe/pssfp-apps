<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Candidature;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Émis après la transition `postulant -> candidat` (soumission en ligne).
 * Le listener `SendCandidatureSubmittedEmail` envoie le récépissé + les
 * instructions de paiement des frais CREMINCAM au candidat.
 */
final class CandidatureSubmitted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Candidature $candidature,
    ) {}
}
