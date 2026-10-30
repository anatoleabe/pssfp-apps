<?php

declare(strict_types=1);

namespace App\Services\EspaceCandidat;

use RuntimeException;

/** Plafond horaire de nouveaux codes atteint, pour ce compte ou pour cet agent. */
final class TropDeNouveauxCodes extends RuntimeException
{
    public function __construct(public readonly bool $parAgent)
    {
        parent::__construct($parAgent
            ? 'Vous avez atteint le nombre maximal de nouveaux codes pour cette heure ('.NouveauCodeCandidat::MAX_PAR_AGENT.').'
            : 'Ce candidat a déjà reçu '.NouveauCodeCandidat::MAX_PAR_COMPTE.' nouveaux codes dans l\'heure. Réessayez plus tard.');
    }
}
