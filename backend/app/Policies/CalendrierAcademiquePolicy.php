<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Services\Lmd\PermissionsLmd;

/** Calendrier académique : consulté par le module LMD et le chef de la structure ; préparé par la scolarité ; jamais supprimé. */
final class CalendrierAcademiquePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsLmd::CONSULTER) || $user->can(PermissionsLmd::CALENDRIER_PREPARER)
            || $user->can(PermissionsLmd::CALENDRIER_SIGNER) || $user->can(PermissionsLmd::CALENDRIER_REAJUSTER);
    }

    public function view(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionsLmd::CALENDRIER_PREPARER);
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
