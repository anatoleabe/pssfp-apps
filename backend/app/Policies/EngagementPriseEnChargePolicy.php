<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Services\Scolarite\EngagementsPriseEnCharge;

/**
 * Engagements de prise en charge (lot A2, §9.3) : lus par qui voit les auditeurs ; créés par
 * qui peut débloquer l'accès. Toute autre écriture passe par les gestes de la fiche, qui
 * revérifient leur propre permission.
 */
final class EngagementPriseEnChargePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_auditeur');
    }

    public function view(User $user): bool
    {
        return $user->can('view_auditeur');
    }

    public function create(User $user): bool
    {
        return EngagementsPriseEnCharge::peutGerer($user);
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
