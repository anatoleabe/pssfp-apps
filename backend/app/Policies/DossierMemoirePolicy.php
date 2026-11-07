<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Services\Lmd\PermissionsLmd;

/** Mémoires : consultés par le module LMD, suivis par la scolarité. Ouverts par délibération, jamais supprimés. */
final class DossierMemoirePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsLmd::CONSULTER) || $user->can(PermissionsLmd::MEMOIRES_SUIVRE) || $user->can(PermissionsLmd::MEMOIRES_ATTESTER);
    }

    public function view(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function create(): bool
    {
        return false;
    }

    public function update(User $user): bool
    {
        return $user->can(PermissionsLmd::MEMOIRES_SUIVRE);
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
