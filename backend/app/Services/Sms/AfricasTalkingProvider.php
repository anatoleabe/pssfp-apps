<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Exceptions\NotConfiguredException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Provider SMS Africa's Talking (LOT B.4) — API REST messaging v1.
 *
 * Appel HTTP direct (pas de SDK : une seule route, dépendance en moins).
 * Activé par SMS_PROVIDER=africas_talking + AFRICAS_TALKING_USERNAME/API_KEY.
 * Le sender id (AFRICAS_TALKING_SENDER_ID) doit être approuvé côté AT.
 *
 * Contrat d'erreur : toute panne lève une RuntimeException — les appelants
 * décident (forgot-pin l'avale et reste 202, anti-énumération).
 */
final class AfricasTalkingProvider implements SmsServiceInterface
{
    private const ENDPOINT = 'https://api.africastalking.com/version1/messaging';

    public function send(string $phoneE164, string $message): void
    {
        $username = (string) config('services.africas_talking.username', '');
        $apiKey = (string) config('services.africas_talking.api_key', '');
        $senderId = (string) config('services.africas_talking.sender_id', '');

        if ($username === '' || $apiKey === '') {
            throw new NotConfiguredException(
                'Africa\'s Talking SMS provider is not configured. '
                .'Set AFRICAS_TALKING_USERNAME and AFRICAS_TALKING_API_KEY, '
                .'or use SMS_PROVIDER=fake in development.'
            );
        }

        $response = Http::asForm()
            ->timeout(10)
            ->withHeaders(['apiKey' => $apiKey, 'Accept' => 'application/json'])
            ->post(self::ENDPOINT, array_filter([
                'username' => $username,
                'to' => $phoneE164,
                'message' => $message,
                'from' => $senderId !== '' ? $senderId : null,
            ]));

        if (! $response->successful()) {
            throw new RuntimeException(
                "Africa's Talking HTTP {$response->status()} — SMS non envoyé."
            );
        }

        // Statut par destinataire : "Success" attendu (sandbox et prod).
        $recipient = $response->json('SMSMessageData.Recipients.0');
        $status = is_array($recipient) ? ($recipient['status'] ?? null) : null;

        if ($status !== 'Success') {
            throw new RuntimeException(
                "Africa's Talking a refusé l'envoi (status: ".($status ?? 'inconnu').').'
            );
        }

        // Trace sans PII : suffixe du numéro uniquement + coût facturé.
        Log::channel('sms')->info('[africas-talking] SMS envoyé', [
            'phone_suffix' => substr($phoneE164, -4),
            'cost' => is_array($recipient) ? ($recipient['cost'] ?? null) : null,
        ]);
    }
}
