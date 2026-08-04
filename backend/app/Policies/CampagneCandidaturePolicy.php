<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CampagneCandidature;
use App\Models\User;

/**
 * Policy CampagneCandidature côté admin Filament.
 *
 * Une campagne pilote l'ouverture des candidatures, la numérotation des
 * dossiers et le communiqué publié sur apply.pssfp.org : elle est **consultable
 * par tous les rôles back-office** mais ne se modifie que par `admin` et
 * `super_admin`. Le verrou est doublé — rôle *et* permission — pour qu'une
 * permission accordée par erreur depuis l'écran des rôles ne suffise jamais à
 * ouvrir ou clore une campagne.
 *
 * delete absent : la migration `candidatures.campagne_id ON DELETE RESTRICT`
 * empêche déjà la suppression tant qu'il y a des dossiers. On ne propose
 * pas l'action côté UI pour éviter une expérience de delete qui échoue.
 */
final class CampagneCandidaturePolicy
{
    /** Seuls rôles autorisés à écrire sur une campagne. */
    private const WRITE_ROLES = ['super_admin', 'admin'];

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
        return $user->hasAnyRole(self::WRITE_ROLES)
            && $user->can('create_campagne::candidature');
    }

    public function update(User $user, CampagneCandidature $campagne): bool
    {
        return $user->hasAnyRole(self::WRITE_ROLES)
            && $user->can('update_campagne::candidature');
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
