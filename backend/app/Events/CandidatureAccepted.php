<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Candidature;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Émis après transition de statut `candidat -> accepte` côté admin Filament.
 * Le listener `SendCandidatureDecisionEmail` s'occupe du dispatch email queue.
 */
final class CandidatureAccepted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Candidature $candidature,
        public readonly ?string $internalComment = null,
    ) {}
}
