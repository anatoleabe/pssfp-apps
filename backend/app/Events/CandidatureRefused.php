<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Candidature;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Émis après transition de statut `candidat -> refuse` côté admin Filament.
 * Le motif est obligatoire (cf. arbitrage B PR D).
 */
final class CandidatureRefused
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Candidature $candidature,
        public readonly string $motif,
    ) {}
}
