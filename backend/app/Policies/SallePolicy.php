<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Services\Lmd\PermissionsLmd;

/** Emplois du temps (lot 12c) : tenus par la scolarité. */
final class SallePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsLmd::CONSULTER) || $user->can(PermissionsLmd::EMPLOIS_DU_TEMPS);
    }

    public function view(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionsLmd::EMPLOIS_DU_TEMPS);
    }

    public function update(User $user): bool
    {
        return $this->create($user);
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
