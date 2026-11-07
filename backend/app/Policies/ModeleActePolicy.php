<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Services\Lmd\PermissionsLmd;

/** Modèles d'actes : délais, signataires et gabarits fixés par le responsable de la scolarité (D19). */
final class ModeleActePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsLmd::CONSULTER) || $user->can(PermissionsLmd::ACTES_PRODUIRE) || $user->can(PermissionsLmd::ACTES_SIGNER);
    }

    public function view(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionsLmd::CALENDRIER_REAJUSTER);
    }

    public function update(User $user): bool
    {
        return $this->create($user);
    }

    /** Un modèle qui a servi se désactive, il ne se supprime pas. */
    public function delete(): bool
    {
        return false;
    }

    public function deleteAny(): bool
    {
        return false;
    }
}
