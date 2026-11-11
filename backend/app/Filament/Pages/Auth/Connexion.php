<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Models\User;
use App\Services\Audit\VerrouillageComptes;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Facades\Filament;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Models\Contracts\FilamentUser;
use Filament\Pages\Auth\Login;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;

/**
 * Connexion au back-office (lot 14d-2). Un compte verrouillé est refusé AVANT toute vérification du mot de passe, en temps
 * constant : sinon la réponse, plus rapide quand le mot de passe est juste, laisserait deviner ce dernier pendant le verrou.
 */
class Connexion extends Login
{
    /** Durée minimale d'un refus, alignée sur l'échec ordinaire (SessionGuard : 200 ms). */
    public const DUREE_MIN_MICROSECONDES = 200_000;

    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $data = $this->form->getState();
        $email = (string) ($data['email'] ?? '');
        $compte = $email === '' ? null : User::query()->where('email', $email)->first();

        if ($compte !== null && VerrouillageComptes::estVerrouille($compte)) {
            (new Timebox)->call(function () use ($compte): void {
                activity(VerrouillageComptes::JOURNAL)->performedOn($compte)->event('connexion_refusee_verrouille')
                    ->withProperties(['ip' => request()->ip(), 'navigateur' => Str::limit((string) request()->userAgent(), 180, '')])
                    ->log('Connexion refusée : compte verrouillé (mot de passe non vérifié)');
            }, self::DUREE_MIN_MICROSECONDES);
            $this->throwFailureValidationException();
        }

        // Suite identique à Login::authenticate (sans second décompte de la limite de débit).
        if (! Filament::auth()->attempt($this->getCredentialsFromFormData($data), $data['remember'] ?? false)) {
            $this->throwFailureValidationException();
        }
        $user = Filament::auth()->user();
        if (($user instanceof FilamentUser) && ! $user->canAccessPanel(Filament::getCurrentPanel())) {
            Filament::auth()->logout();
            $this->throwFailureValidationException();
        }
        session()->regenerate();

        return app(LoginResponse::class);
    }
}
