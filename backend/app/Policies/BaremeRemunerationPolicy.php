<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Services\Lmd\PermissionsLmd;

/** Barème des enseignants (lot 13b) : lu par la paie, modifié par la préparation, validé par la vérification. */
final class BaremeRemunerationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsLmd::PAIE_PREPARER) || $user->can(PermissionsLmd::PAIE_VERIFIER)
            || $user->can(PermissionsLmd::PAIE_VISER) || $user->can(PermissionsLmd::PAIE_PAYER);
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
        return $user->can(PermissionsLmd::PAIE_PREPARER);
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
