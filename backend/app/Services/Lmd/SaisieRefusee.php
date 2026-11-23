<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use InvalidArgumentException;

/**
 * Lot de notes refusé au contrôle (spec lot 20) : rien n'est écrit. Le message reste celui que
 * l'écran affichait ; `erreurs` donne chaque ligne refusée, sans limite.
 */
final class SaisieRefusee extends InvalidArgumentException
{
    /** @param  list<array{id: int, matricule: string, message: string}>  $erreurs  par inscription pédagogique */
    public function __construct(string $message, public readonly array $erreurs)
    {
        parent::__construct($message);
    }
}
