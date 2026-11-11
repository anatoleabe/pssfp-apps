<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Services\Audit\VerrouillageComptes;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Str;

/**
 * Connexions du personnel au back-office (garde `web`) dans le journal
 * d'activité. Les candidats se connectent par jeton (API) et ont leur propre
 * journal (`auth.candidat`). Jamais de mot de passe saisi dans le journal.
 */
final class JournaliserConnexionsPersonnel
{
    public function handleLogin(Login $event): void
    {
        if ($event->guard !== 'web' || ! $event->user instanceof User) {
            return;
        }

        if (VerrouillageComptes::estVerrouille($event->user)) {
            // Mot de passe juste, mais compte verrouillé (lot 14d-2) : le panneau refusera l'accès ; le compteur n'est pas remis à zéro.
            activity('connexions')->causedBy($event->user)->performedOn($event->user)->event('connexion_refusee_verrouille')
                ->withProperties($this->contexte())->log('Connexion refusée : compte verrouillé');

            return;
        }
        app(VerrouillageComptes::class)->succes($event->user);
        activity('connexions')->causedBy($event->user)->performedOn($event->user)
            ->event('connexion')
            ->withProperties($this->contexte())
            ->log('Connexion au back-office');
    }

    public function handleLogout(Logout $event): void
    {
        // Un compte verrouillé n'a pas ouvert de session : sa « déconnexion » (refus du panneau) n'en est pas une.
        if ($event->guard !== 'web' || ! $event->user instanceof User || VerrouillageComptes::estVerrouille($event->user)) {
            return;
        }

        activity('connexions')->causedBy($event->user)->performedOn($event->user)
            ->event('deconnexion')
            ->withProperties($this->contexte())
            ->log('Déconnexion du back-office');
    }

    public function handleFailed(Failed $event): void
    {
        if ($event->guard !== 'web') {
            return;
        }

        $journal = activity('connexions')->event('connexion_echouee')
            ->withProperties($this->contexte() + [
                // Identifiant saisi seulement, jamais le mot de passe.
                'identifiant' => Str::limit((string) ($event->credentials['email'] ?? ''), 150, ''),
            ]);
        if ($event->user instanceof User) {
            $journal->performedOn($event->user);
        }
        $journal->log('Échec de connexion au back-office');
        if ($event->user instanceof User) {
            app(VerrouillageComptes::class)->echec($event->user);
        }
    }

    /** @return array{ip: ?string, navigateur: string} */
    private function contexte(): array
    {
        return [
            'ip' => request()->ip(),
            'navigateur' => Str::limit((string) request()->userAgent(), 180, ''),
        ];
    }
}
