<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SyntheseAnimation;
use App\Models\User;
use App\Services\Lmd\PermissionsLmd;
use App\Services\Lmd\SynthesesAnimation;

/**
 * Synthèses d'animation en ligne (lot 13b-2) : réservées à la Scolarité (droit de pointage), comme les états de paie
 * (montants de paie : pas d'accès en simple consultation du LMD). Jamais supprimées.
 */
final class SyntheseAnimationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionsLmd::POINTAGE);
    }

    public function view(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionsLmd::POINTAGE);
    }

    public function update(User $user, SyntheseAnimation $s): bool
    {
        return app(SynthesesAnimation::class)->peutModifier($s, $user);
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
