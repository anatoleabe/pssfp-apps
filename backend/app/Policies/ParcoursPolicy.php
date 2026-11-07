<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Services\Lmd\PermissionsLmd;

/** Parcours : lecture du module, modification par la préparation du référentiel. Jamais supprimés (désactivés). */
final class ParcoursPolicy
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

    public function delete(): bool
    {
        return false;
    }

    public function deleteAny(): bool
    {
        return false;
    }
}
