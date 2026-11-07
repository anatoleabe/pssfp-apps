<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MembreJury;
use App\Models\User;
use App\Services\Lmd\PermissionsLmd;

/** Délibérations : consultées par le module ; jamais supprimées. Les gestes passent par App\Services\Lmd\Deliberations. */
final class DeliberationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsLmd::CONSULTER) || $this->membreDeJury($user);
    }

    public function view(User $user): bool
    {
        return $user->can(PermissionsLmd::CONSULTER) || $this->membreDeJury($user);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionsLmd::DELIBERATION_PREPARER);
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

    private function membreDeJury(User $user): bool
    {
        return $user->can(PermissionsLmd::JURY_SIGNER) && MembreJury::query()->where('user_id', $user->id)->exists();
    }
}
