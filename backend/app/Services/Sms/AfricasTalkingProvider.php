<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Exceptions\NotConfiguredException;
use LogicException;

/**
 * Stub Africa's Talking — intégration V1 incomplète.
 *
 * La vraie implémentation arrivera en Phase prod (cf. spec module 5 §M13).
 * Pour l'instant :
 * - Si la config est absente → NotConfiguredException (fail-fast).
 * - Si la config est présente → LogicException explicite.
 *
 * Permet au container Laravel de booter sans erreur tant qu'on n'envoie
 * pas réellement de SMS. Tests : `SMS_PROVIDER=fake` partout dans CI.
 */
final class AfricasTalkingProvider implements SmsServiceInterface
{
    public function send(string $phoneE164, string $message): void
    {
        if (
            ! config('services.africas_talking.username')
            || ! config('services.africas_talking.api_key')
        ) {
            throw new NotConfiguredException(
                'Africa\'s Talking SMS provider is not configured. '
                .'Set AFRICAS_TALKING_USERNAME and AFRICAS_TALKING_API_KEY, '
                .'or use SMS_PROVIDER=fake in development.'
            );
        }

        throw new LogicException(
            'AfricasTalkingProvider is a stub in V1. '
            .'Switch SMS_PROVIDER=fake until Phase prod brings the real integration.'
        );
    }
}
