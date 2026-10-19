<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Droits du thésaurus bibliothèque : créer reste dans « gestion », mais
 * renommer, déplacer ou supprimer exige en plus `library.thesaurus`.
 */
final class MotClePolicy extends ContentPolicy
{
    protected function resource(): string
    {
        return 'mot::cle';
    }

    public function update(User $user): bool
    {
        return parent::update($user) && $user->can('library.thesaurus');
    }

    public function delete(User $user): bool
    {
        return parent::delete($user) && $user->can('library.thesaurus');
    }

    public function deleteAny(User $user): bool
    {
        return $this->delete($user);
    }

    public function restore(User $user): bool
    {
        return $this->update($user);
    }
}
