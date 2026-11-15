<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Services\Scolarite\Etiquettes;

/**
 * Écran des étiquettes : réservé à qui pose les étiquettes. Les voir sur les
 * auditeurs ne demande que l'accès aux auditeurs (AuditeurPolicy).
 * Pas de suppression groupée : chaque suppression annonce ses porteurs.
 */
final class EtiquettePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Etiquettes::PERMISSION);
    }

    public function view(User $user): bool
    {
        return $user->can(Etiquettes::PERMISSION);
    }

    public function create(User $user): bool
    {
        return $user->can(Etiquettes::PERMISSION);
    }

    public function update(User $user): bool
    {
        return $user->can(Etiquettes::PERMISSION);
    }

    public function delete(User $user): bool
    {
        return $user->can(Etiquettes::PERMISSION);
    }

    public function deleteAny(): bool
    {
        return false;
    }
}
