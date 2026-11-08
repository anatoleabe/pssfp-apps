<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Services\Lmd\PermissionsLmd;

/** Quotas horaires (lot 12d) : tenus par la scolarité ; les écritures passent par le service Programmations. */
final class QuotaHorairePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsLmd::CONSULTER) || $user->can(PermissionsLmd::PROGRAMMATION) || $user->can(PermissionsLmd::PROGRAMMATION_VALIDER);
    }

    public function view(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionsLmd::PROGRAMMATION);
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
