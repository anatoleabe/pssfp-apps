<?php

declare(strict_types=1);

namespace App\Policies;

/** Droits Page — cf. ContentPolicy pour le schéma commun du Module 1. */
final class PagePolicy extends ContentPolicy
{
    protected function resource(): string
    {
        return 'page';
    }
}
