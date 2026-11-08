<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PointageEnseignant;
use App\Models\User;
use App\Services\Lmd\PermissionsLmd;

/** Pointages des enseignants (lot 13a) : la scolarité les voit tous ; l'enseignant, les siens seulement. */
final class PointageEnseignantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->gestion($user) || $user->can(PermissionsLmd::EMARGER);
    }

    public function view(User $user, PointageEnseignant $p): bool
    {
        return $this->gestion($user) || ($user->can(PermissionsLmd::EMARGER) && $p->enseignant_id === $user->id);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionsLmd::POINTAGE) || $user->can(PermissionsLmd::EMARGER);
    }

    public function update(User $user, PointageEnseignant $p): bool
    {
        return ! $p->estPaye() && ($user->can(PermissionsLmd::POINTAGE)
            || ($user->can(PermissionsLmd::EMARGER) && $p->enseignant_id === $user->id && $p->statut !== PointageEnseignant::VALIDE));
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
        return $user->can(PermissionsLmd::CONSULTER) || $user->can(PermissionsLmd::POINTAGE);
    }
}
