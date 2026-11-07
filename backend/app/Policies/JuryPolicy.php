<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Jury;
use App\Models\User;
use App\Services\Lmd\PermissionsLmd;

/** Jurys : consultés par le module, préparés par la scolarité ; composition figée dès qu'une séance s'ouvre (D37). */
final class JuryPolicy
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
        return $user->can(PermissionsLmd::DELIBERATION_PREPARER);
    }

    public function update(User $user, Jury $jury): bool
    {
        return $user->can(PermissionsLmd::DELIBERATION_PREPARER) && ! $jury->compositionFigee();
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
