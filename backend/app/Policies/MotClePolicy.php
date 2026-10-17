<?php

declare(strict_types=1);

namespace App\Policies;

/** Droits du thésaurus bibliothèque. */
final class MotClePolicy extends ContentPolicy
{
    protected function resource(): string
    {
        return 'mot::cle';
    }
}
