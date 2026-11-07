<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Services\Lmd\PermissionsLmd;

/** Attributions des ECUE : consultées par le module, gérées par la préparation du référentiel. */
final class AttributionEcuePolicy
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

    public function update(User $user): bool
    {
        return $user->can(PermissionsLmd::REFERENTIEL);
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
