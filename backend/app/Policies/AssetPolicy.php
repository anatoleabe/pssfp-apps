<?php

declare(strict_types=1);

namespace App\Policies;

/** Droits Asset — cf. ContentPolicy pour le schéma commun du Module 1. */
final class AssetPolicy extends ContentPolicy
{
    protected function resource(): string
    {
        return 'asset';
    }
}
