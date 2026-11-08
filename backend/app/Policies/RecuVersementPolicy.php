<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Services\Scolarite\RecusVersements;

/** Reçus (Scolarité lot 4) : lus par qui consulte les auditeurs ; émis et annulés par le service financier ; jamais modifiés. */
final class RecuVersementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_auditeur') || $user->can(RecusVersements::PERMISSION_EMETTRE);
    }

    public function view(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function create(): bool
    {
        return false;
    }

    public function update(): bool
    {
        return false;
    }

    public function delete(): bool
    {
        return false;
    }

    public function deleteAny(): bool
    {
        return false;
    }
}
