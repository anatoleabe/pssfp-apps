<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Services\Lmd\PermissionsLmd;

/** Dossiers de paie (lot 13a) : Scolarité (statut) et service financier (NIU, banque, compte). Jamais supprimés. */
final class DossierPaieEnseignantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->acces($user);
    }

    public function view(User $user): bool
    {
        return $this->acces($user);
    }

    public function create(User $user): bool
    {
        return $this->acces($user);
    }

    public function update(User $user): bool
    {
        return $this->acces($user);
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
        return $user->can(PermissionsLmd::POINTAGE) || $user->can(PermissionsLmd::DOSSIER_PAIE);
    }
}
