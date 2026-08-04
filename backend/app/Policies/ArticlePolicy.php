<?php

declare(strict_types=1);

namespace App\Policies;

/** Droits Article — cf. ContentPolicy pour le schéma commun du Module 1. */
final class ArticlePolicy extends ContentPolicy
{
    protected function resource(): string
    {
        return 'article';
    }
}
