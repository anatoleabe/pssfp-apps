<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Services\Acma\ArchivesAcma;

/** Paiements de l'archive ACMA (lot 15d) : lus avec `archives.consulter`, jamais écrits depuis l'écran. */
final class AcmaPaiementPolicy
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
}
