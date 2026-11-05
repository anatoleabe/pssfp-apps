<?php

declare(strict_types=1);

namespace App\Services\Ecampus;

use RuntimeException;

/**
 * Le message reste affichable : il ne contient jamais le jeton ni un mot de passe.
 * $general : le problème vaut pour tous les dossiers, un lot doit s'arrêter.
 */
final class EchecEcampus extends RuntimeException
{
    public function __construct(string $message, public readonly bool $general = false)
    {
        parent::__construct($message);
    }
}
