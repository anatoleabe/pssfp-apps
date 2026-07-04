<?php

declare(strict_types=1);

namespace App\Services\Security;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Adaptateur de vérification Cloudflare Turnstile (captcha anti-robot).
 *
 * Protège les endpoints publics candidat (inscription, forgot-pin) contre les
 * bots — et, pour forgot-pin, l'épuisement du crédit SMS OTP (cf. spec §M13).
 *
 * Dégradation gracieuse :
 *  - Aucune clé secrète configurée (dev / tests / bascule) => bypass (true).
 *  - Cloudflare injoignable => fail-open + log (on ne bloque pas un candidat
 *    légitime pour une panne réseau ; le rate-limit backend reste actif).
 */
final class TurnstileVerifier
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function verify(?string $token, ?string $ip = null): bool
    {
        $secret = (string) config('services.turnstile.secret', '');

        if ($secret === '') {
            // Bypass assumé en dev/tests, mais jamais silencieux en prod : un
            // oubli de TURNSTILE_SECRET_KEY annulerait tout le durcissement
            // anti-bot (et la protection du crédit SMS) sans aucun signal.
            if (app()->environment('production')) {
                Log::channel('single')->error(
                    'TURNSTILE_SECRET_KEY absent en production — captcha désactivé (bypass).'
                );
            }

            return true;
        }

        if ($token === null || $token === '') {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(5)
                ->post(self::VERIFY_URL, array_filter([
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $ip,
                ]));
        } catch (Throwable $e) {
            Log::channel('single')->warning('Turnstile siteverify injoignable — fail-open.', [
                'error' => $e->getMessage(),
            ]);

            return true;
        }

        return $response->successful() && $response->json('success') === true;
    }
}
