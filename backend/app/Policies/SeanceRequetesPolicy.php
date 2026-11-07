<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Services\Lmd\PermissionsLmd;

/** Séances du jury des requêtes : tenues par la scolarité, validées par le chef de la commission. */
final class SeanceRequetesPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsLmd::REQUETES_JURY) || $user->can(PermissionsLmd::REQUETES_VALIDER) || $user->can(PermissionsLmd::REQUETES_TRAITER);
    }

    public function view(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionsLmd::REQUETES_JURY);
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
