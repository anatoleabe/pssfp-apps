<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Services\Lmd\PermissionsLmd;

/** Actes du calendrier : produits par la scolarité, signature enregistrée par le responsable ou le chef de la structure. */
final class ActePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsLmd::CONSULTER) || $user->can(PermissionsLmd::ACTES_PRODUIRE) || $user->can(PermissionsLmd::ACTES_SIGNER);
    }

    public function view(User $user): bool
    {
        return $this->viewAny($user);
    }

    /** Les actes naissent de la génération, jamais d'une saisie libre. */
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
