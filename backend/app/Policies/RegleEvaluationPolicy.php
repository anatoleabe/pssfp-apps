<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Services\Lmd\PermissionsLmd;

/** Règles d'évaluation : consultées par le module, modifiées avec lmd.regles. Jamais supprimées. */
final class RegleEvaluationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsLmd::CONSULTER);
    }

    public function view(User $user): bool
    {
        return $user->can(PermissionsLmd::CONSULTER);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionsLmd::REGLES);
    }

    public function update(User $user): bool
    {
        return $user->can(PermissionsLmd::REGLES);
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
