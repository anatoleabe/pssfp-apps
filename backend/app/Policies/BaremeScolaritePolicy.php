<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/** Barèmes : réservés au responsable de scolarité et à l'administration. Jamais supprimés. */
final class BaremeScolaritePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('scolarite.bareme');
    }

    public function view(User $user): bool
    {
        return $user->can('scolarite.bareme');
    }

    public function create(User $user): bool
    {
        return $user->can('scolarite.bareme');
    }

    public function update(User $user): bool
    {
        return $user->can('scolarite.bareme');
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
