<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ProgrammationEnseignant;
use App\Models\User;
use App\Services\Lmd\PermissionsLmd;

/** Programmations (lot 12d) : la scolarité les voit toutes ; l'enseignant, les siennes seulement. */
final class ProgrammationEnseignantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->gestion($user) || $user->can(PermissionsLmd::PROGRAMMATION_REPONDRE);
    }

    public function view(User $user, ProgrammationEnseignant $p): bool
    {
        return $this->gestion($user) || ($user->can(PermissionsLmd::PROGRAMMATION_REPONDRE) && $p->enseignant_id === $user->id);
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

    private function gestion(User $user): bool
    {
        return $user->can(PermissionsLmd::CONSULTER) || $user->can(PermissionsLmd::PROGRAMMATION) || $user->can(PermissionsLmd::PROGRAMMATION_VALIDER);
    }
}
