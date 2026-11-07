<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Services\Lmd\PermissionsLmd;

/** Séances : scolarité (assiduité) et enseignants (leurs ECUE, contrôlé par le service). Jamais supprimées. */
final class SeancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsLmd::ASSIDUITE) || $user->can(PermissionsLmd::NOTES_ENSEIGNANT);
    }

    public function view(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
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
