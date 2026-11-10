<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Message;
use App\Models\User;
use App\Services\Messagerie\Messagerie;

/** Messagerie interne (lot 14b-1) : chacun ne lit que ses messages ; un message envoyé ne se modifie ni ne se supprime. */
class MessagePolicy
{
    public function viewAny(User $user): bool
    {
        return Messagerie::personnel()->whereKey($user->id)->exists();
    }

    public function view(User $user, Message $message): bool
    {
        return app(Messagerie::class)->peutVoir($user, $message);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Message $message): bool
    {
        return false;
    }

    public function delete(User $user, Message $message): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Message $message): bool
    {
        return false;
    }

    public function forceDelete(User $user, Message $message): bool
    {
        return false;
    }
}
