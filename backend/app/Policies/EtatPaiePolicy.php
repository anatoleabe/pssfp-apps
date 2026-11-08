<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Services\Lmd\PermissionsLmd;

/** États de paie (lot 13b) : Scolarité (préparation, vérification), contrôleur interne, service financier. Jamais supprimés. */
final class EtatPaiePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsLmd::PAIE_PREPARER) || $user->can(PermissionsLmd::PAIE_VERIFIER)
            || $user->can(PermissionsLmd::PAIE_VISER) || $user->can(PermissionsLmd::PAIE_PAYER);
    }

    public function view(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionsLmd::PAIE_PREPARER);
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
