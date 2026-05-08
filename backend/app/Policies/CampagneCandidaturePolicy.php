<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CampagneCandidature;
use App\Models\User;

/**
 * Policy CampagneCandidature côté admin Filament.
 *
 * delete absent : la migration `candidatures.campagne_id ON DELETE RESTRICT`
 * empêche déjà la suppression tant qu'il y a des dossiers. On ne propose
 * pas l'action côté UI pour éviter une expérience de delete qui échoue.
 */
final class CampagneCandidaturePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_campagne::candidature');
    }

    public function view(User $user, CampagneCandidature $campagne): bool
    {
        return $user->can('view_campagne::candidature');
    }

    public function create(User $user): bool
    {
        return $user->can('create_campagne::candidature');
    }

    public function update(User $user, CampagneCandidature $campagne): bool
    {
        return $user->can('update_campagne::candidature');
    }

    public function delete(User $user, CampagneCandidature $campagne): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
