<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Policy des comptes back-office (Resource Filament UserResource).
 *
 * Deux garde-fous structurels, en plus des permissions shield :
 * - on ne se supprime pas soi-même (verrouillage assuré du panel) ;
 * - seul un super_admin touche à un autre super_admin, pour qu'un `admin`
 *   ne puisse pas neutraliser la direction ni s'auto-promouvoir.
 */
final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_user');
    }

    public function view(User $user, User $model): bool
    {
        return $user->can('view_user');
    }

    public function create(User $user): bool
    {
        return $user->can('create_user');
    }

    public function update(User $user, User $model): bool
    {
        if (! $user->can('update_user')) {
            return false;
        }

        if ($model->hasRole('super_admin') && ! $user->hasRole('super_admin')) {
            return false;
        }

        return true;
    }

    public function delete(User $user, User $model): bool
    {
        if (! $user->can('delete_user')) {
            return false;
        }

        if ($user->is($model)) {
            return false;
        }

        if ($model->hasRole('super_admin') && ! $user->hasRole('super_admin')) {
            return false;
        }

        // Un compte candidat n'est jamais supprimé depuis cet écran : ses
        // candidatures et leurs pièces en dépendent (purge RGPD dédiée).
        return ! $model->hasRole('candidat');
    }

    public function deleteAny(User $user): bool
    {
        // Suppression en masse volontairement interdite sur des comptes.
        return false;
    }
}
