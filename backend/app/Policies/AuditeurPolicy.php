<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/** Fiches auditeurs : lecture seule, toute écriture passe par RegistreVersements. */
final class AuditeurPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_auditeur');
    }

    public function view(User $user): bool
    {
        return $user->can('view_auditeur');
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
