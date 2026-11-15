<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Services\Acma\ArchivesAcma;

/**
 * Archives ACMA (lot 15, §7.10) : consultées avec `archives.consulter`. L'archive ne se crée, ne se modifie et ne se
 * supprime jamais depuis l'écran : compléments et arbitrages passent par ArchivesAcma, qui revérifie ses permissions.
 */
final class AcmaDossierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(ArchivesAcma::CONSULTER);
    }

    public function view(User $user): bool
    {
        return $user->can(ArchivesAcma::CONSULTER);
    }

    public function create(): bool
    {
        return false;
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
