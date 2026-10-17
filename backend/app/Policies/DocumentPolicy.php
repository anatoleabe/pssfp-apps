<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/** Droits Document (Module 3) — même schéma que les contenus du Module 1. */
final class DocumentPolicy extends ContentPolicy
{
    protected function resource(): string
    {
        return 'document';
    }

    public function publish(User $user): bool
    {
        return $user->can('document.publish');
    }
}
