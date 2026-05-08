<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Candidature;
use App\Models\User;

/**
 * Policy Candidature côté admin Filament (cf. PR D plan §C — hybride shield).
 *
 * - viewAny / view / update : permissions filament-shield style
 *   (`view_any_candidature`, `view_candidature`, `update_candidature`).
 * - delete absent : pas de suppression via panel — RGPD passe par CLI dédiée.
 *
 * Note : il existe déjà une notion d'ownership côté API publique
 * (un candidat ne lit que ses propres dossiers via /v1/applications/me).
 * Ici on parle du panel admin où ces règles ne s'appliquent pas — l'admin
 * voit tous les dossiers de toutes les campagnes accessibles.
 */
final class CandidaturePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_candidature');
    }

    public function view(User $user, Candidature $candidature): bool
    {
        return $user->can('view_candidature');
    }

    public function update(User $user, Candidature $candidature): bool
    {
        if (! $user->can('update_candidature')) {
            return false;
        }

        // Lecture seule sur les dossiers décidés (cf. P-min-1 PR D),
        // sauf super_admin qui peut corriger une erreur exceptionnelle.
        if (in_array($candidature->statut, [Candidature::STATUT_ACCEPTE, Candidature::STATUT_REFUSE], true)) {
            return $user->hasRole('super_admin');
        }

        return true;
    }

    public function create(User $user): bool
    {
        // Pas de création via panel admin — un dossier se crée uniquement
        // via le candidat authentifié sur /v1/applications/me (cf. arbitrage D PR D).
        return false;
    }

    public function delete(User $user, Candidature $candidature): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
