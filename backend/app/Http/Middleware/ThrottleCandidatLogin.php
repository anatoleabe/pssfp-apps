<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\CandidatAuthThrottle;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pré-vérifie les compteurs IP / phone avant de laisser la requête atteindre
 * le contrôleur de login candidat. La logique de mise à jour des compteurs
 * (success/failure) reste dans le contrôleur lui-même.
 *
 * Codes retournés :
 * - 429 Too Many Requests : ip_throttled (3/10min) ou ip_banned (50/24h)
 * - 423 Locked            : phone_locked (10 échecs/30min)
 *
 * Wired dans bootstrap/app.php : alias `throttle.candidat.login`.
 */
final class ThrottleCandidatLogin
{
    public function __construct(private readonly CandidatAuthThrottle $throttle) {}

    public function handle(Request $request, Closure $next): Response
    {
        $phone = (string) $request->input('phone_e164', '');
        $ip = (string) $request->ip();

        if ($phone === '' || ! preg_match('/^\+[1-9]\d{6,14}$/', $phone)) {
            // Validation déléguée au FormRequest, on laisse passer.
            return $next($request);
        }

        $throttleResult = $this->throttle->checkLogin($phone, $ip);

        if ($throttleResult === null) {
            return $next($request);
        }

        $status = match ($throttleResult['kind']) {
            'phone_locked' => 423,
            'ip_banned' => 429,
            'ip_throttled' => 429,
            default => 429,
        };

        return response()->json([
            'message' => match ($throttleResult['kind']) {
                'phone_locked' => 'Compte temporairement bloqué après trop d\'échecs. Réessayez plus tard.',
                'ip_banned' => 'Trop de tentatives depuis cette adresse. Réessayez dans quelques heures.',
                default => 'Trop de tentatives. Réessayez dans quelques minutes.',
            },
            'kind' => $throttleResult['kind'],
            'retry_after' => $throttleResult['retry_after'],
        ], $status, [
            'Retry-After' => (string) $throttleResult['retry_after'],
        ]);
    }
}
