<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
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

        activity('connexions')->causedBy($event->user)->performedOn($event->user)
            ->event('connexion')
            ->withProperties($this->contexte())
            ->log('Connexion au back-office');
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->guard !== 'web' || ! $event->user instanceof User) {
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
