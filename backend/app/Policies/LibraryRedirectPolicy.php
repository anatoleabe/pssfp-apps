<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/** Anciennes adresses de la bibliothèque : tout passe par `library.redirects`. */
final class LibraryRedirectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('library.redirects');
    }

    public function view(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->viewAny($user);
    }
}
