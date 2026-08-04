<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Socle commun aux contenus éditoriaux du Module 1 (pages, articles, médias).
 *
 * Les trois ressources partagent exactement le même schéma de droits
 * `{action}_{ressource}` ; seul le suffixe change, d'où cette classe de base
 * paramétrée plutôt que trois policies identiques.
 */
abstract class ContentPolicy
{
    /** Suffixe des permissions : `page`, `article`, `asset`. */
    abstract protected function resource(): string;

    public function viewAny(User $user): bool
    {
        return $user->can("view_any_{$this->resource()}");
    }

    public function view(User $user): bool
    {
        return $user->can("view_{$this->resource()}");
    }

    public function create(User $user): bool
    {
        return $user->can("create_{$this->resource()}");
    }

    public function update(User $user): bool
    {
        return $user->can("update_{$this->resource()}");
    }

    public function delete(User $user): bool
    {
        return $user->can("delete_{$this->resource()}");
    }

    public function deleteAny(User $user): bool
    {
        return $user->can("delete_{$this->resource()}");
    }

    public function restore(User $user): bool
    {
        return $user->can("update_{$this->resource()}");
    }

    public function forceDelete(User $user): bool
    {
        return $user->hasRole('super_admin');
    }
}
