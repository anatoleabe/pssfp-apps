<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Maquette;
use App\Models\User;
use App\Services\Lmd\PermissionsLmd;

/** Maquettes : lecture du module, préparation en brouillon seulement, jamais supprimées une fois validées. */
final class MaquettePolicy
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
        return $user->can(PermissionsLmd::REFERENTIEL);
    }

    public function update(User $user, Maquette $maquette): bool
    {
        return $maquette->estModifiable() && $user->can(PermissionsLmd::REFERENTIEL);
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
