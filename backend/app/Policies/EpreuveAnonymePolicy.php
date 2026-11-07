<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Services\Lmd\PermissionsLmd;

/** Épreuves anonymes : gestionnaire, opérateurs de saisie et témoin de la levée. Jamais supprimées. */
final class EpreuveAnonymePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsLmd::ANONYMAT_GERER) || $user->can(PermissionsLmd::ANONYMAT_SAISIR) || $user->can(PermissionsLmd::NOTES_VERROUILLER);
    }

    public function view(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionsLmd::ANONYMAT_GERER);
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
