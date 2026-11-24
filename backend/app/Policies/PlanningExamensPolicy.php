<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Services\Lmd\PermissionsLmd;

/** Plannings d'examens (lot 27) : tenus par la scolarité, avec le droit des emplois du temps. */
final class PlanningExamensPolicy
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
