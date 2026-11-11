<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Filament\Pages\MaSecurite;
use App\Mail\AlerteSecuriteMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Lot 14d-4 : quand la double authentification d'un compte change, son titulaire est prévenu par courriel (un intrus qui
 * connaît le mot de passe et enrôle son propre téléphone ne passe pas inaperçu), et le jeton « se souvenir de moi » change.
 */
final class AlertesSecurite
{
    public function signaler(User $compte, string $evenement): void
    {
        $compte->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
        if (blank($compte->email)) {
            return;
        }
        try {
            Mail::to($compte->email)->send(new AlerteSecuriteMail(
                $compte->name, $evenement, now('Africa/Douala')->format('d/m/Y à H:i'), request()->ip(), MaSecurite::getUrl(panel: 'admin'),
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
