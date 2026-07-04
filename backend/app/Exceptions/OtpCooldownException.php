<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Levée par OtpService::generate() quand le numéro a déjà reçu un OTP trop
 * récemment (cooldown) ou a atteint le plafond horaire (LOT B.4).
 *
 * L'appelant anti-énumération (forgot-pin) doit l'avaler : on répond 202
 * sans envoyer de SMS — un 429 révélerait que le numéro est enregistré.
 */
final class OtpCooldownException extends RuntimeException
{
    public function __construct(
        public readonly int $retryAfterSeconds,
        public readonly string $reason, // 'cooldown' | 'hourly_cap'
    ) {
        parent::__construct(
            "OTP generation throttled ({$reason}) — retry in {$retryAfterSeconds}s."
        );
    }
}
