<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use InvalidArgumentException;

/**
 * Refus R4.1 de D1 : un versement imputé sur plusieurs années ne s'annule que par la
 * demande d'autorisation du sous-lot D4 (décision 8). Message destiné à l'agent.
 */
final class AnnulationMultiAnnees extends InvalidArgumentException
{
    /** @param  list<string>  $annees  années touchées, croissantes */
    public function __construct(public readonly array $annees)
    {
        parent::__construct('Ce versement est imputé sur plusieurs années ('.implode(', ', $annees).') : son annulation passera par une demande d\'autorisation (sous-lot D4). Prévenez l\'USI.');
    }
}
