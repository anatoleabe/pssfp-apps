<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

/**
 * Policy des rôles back-office.
 *
 * Deux garde-fous s'ajoutent aux permissions :
 *
 * 1. Les rôles structurants (`super_admin`, `candidat`) ne se modifient ni ne
 *    se suppriment depuis l'interface : le premier est le filet de sécurité du
 *    projet, le second est piloté par le portail candidat.
 * 2. Un administrateur ne peut pas modifier un rôle qu'il détient lui-même —
 *    sans quoi élargir « son » rôle reviendrait à s'octroyer des droits qu'on
 *    ne lui a pas donnés. Le super_admin, lui, n'est pas concerné.
 */
final class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_role');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->can('view_role');
    }

    public function create(User $user): bool
    {
        return $user->can('create_role');
    }

    public function update(User $user, Role $role): bool
    {
        return ! $role->isLocked()
            && $user->can('update_role')
            && ! $this->wouldEscalateOwnPrivileges($user, $role);
    }

    public function delete(User $user, Role $role): bool
    {
        return ! $role->isLocked()
            && $user->can('delete_role')
            && ! $this->wouldEscalateOwnPrivileges($user, $role)
            // Un rôle encore attribué ne se supprime pas : les comptes
            // concernés se retrouveraient sans périmètre, donc bloqués.
            && $role->users()->doesntExist();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function wouldEscalateOwnPrivileges(User $user, Role $role): bool
    {
        return ! $user->hasRole('super_admin') && $user->hasRole($role->name);
    }
}
