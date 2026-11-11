<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DemandeAutorisation;
use App\Models\User;
use App\Services\Messagerie\Demandes;
use App\Services\Messagerie\Messagerie;

/** Demandes d'autorisation (lot 14c) : décisions par le service, jamais par modification directe. */
class DemandeAutorisationPolicy
{
    public function viewAny(User $user): bool
    {
        return Messagerie::personnel()->whereKey($user->id)->exists();
    }

    public function view(User $user, DemandeAutorisation $demande): bool
    {
        return app(Demandes::class)->visiblesPar($user)->whereKey($demande->id)->exists();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, DemandeAutorisation $demande): bool
    {
        return false;
    }

    public function delete(User $user, DemandeAutorisation $demande): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
