<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Services\Lmd\PermissionsLmd;

/** Requêtes : vues par la scolarité et la commission ; jamais créées ni supprimées depuis l'administration. */
final class RequetePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->acces($user);
    }

    public function view(User $user): bool
    {
        return $this->acces($user);
    }

    public function create(): bool
    {
        return false;
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

    private function acces(User $user): bool
    {
        return $user->can(PermissionsLmd::REQUETES_TRAITER) || $user->can(PermissionsLmd::REQUETES_COTER)
            || $user->can(PermissionsLmd::REQUETES_JURY) || $user->can(PermissionsLmd::REQUETES_VALIDER);
    }
}
