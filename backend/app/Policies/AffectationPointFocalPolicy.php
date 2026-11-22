<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Services\Lmd\PermissionsLmd;

/** Affectations des points focaux (spec lot 16b) : consultées par le module, gérées par la préparation du référentiel. Sans modification : on retire et on réaffecte. */
final class AffectationPointFocalPolicy
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
        return $user->can(PermissionsLmd::REFERENTIEL);
    }

    public function update(): bool
    {
        return false;
    }

    public function delete(User $user): bool
    {
        return $user->can(PermissionsLmd::REFERENTIEL);
    }

    public function deleteAny(): bool
    {
        return false;
    }
}
