<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\IndisponibiliteEnseignant;
use App\Models\User;
use App\Services\Lmd\PermissionsLmd;

/** Indisponibilités (lot 12d) : la scolarité les gère toutes ; l'enseignant, les siennes. */
final class IndisponibiliteEnseignantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsLmd::PROGRAMMATION) || $user->can(PermissionsLmd::PROGRAMMATION_REPONDRE);
    }

    public function view(User $user, IndisponibiliteEnseignant $i): bool
    {
        return $user->can(PermissionsLmd::PROGRAMMATION) || ($user->can(PermissionsLmd::PROGRAMMATION_REPONDRE) && $i->enseignant_id === $user->id);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(): bool
    {
        return false;
    }

    public function delete(User $user, IndisponibiliteEnseignant $i): bool
    {
        return $this->view($user, $i);
    }

    public function deleteAny(): bool
    {
        return false;
    }
}
