<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Exceptions\NotConfiguredException;
use App\Support\PhoneMasker;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Passerelle SMS auto-hébergée exposant une API Laravel/Sanctum.
 *
 * Contrat (POST {base}/sent/compose, multipart/form-data, Bearer token) :
 *   from_type    : 'sender_id' | 'phone_number'
 *   sender_id    : identifiant du sender (si from_type = sender_id)
 *   from_number  : numéro émetteur      (si from_type = phone_number)
 *   to_numbers[] : JSON par destinataire, ex. {"value":"+237691234567"}
 *   body         : texte du message
 *
 * Réponse attendue : {"status":"success","message":"..."}. Le champ `status`
 * fait foi — la passerelle répond 200 y compris sur certains refus métier,
 * donc un code HTTP 2xx seul ne prouve pas l'envoi.
 *
 * Activé par SMS_PROVIDER=gateway_api.
 */
final class GatewayApiProvider implements SmsServiceInterface
{
    private const TIMEOUT_SECONDS = 15;

    public function send(string $phoneE164, string $message): void
    {
        $baseUrl = rtrim((string) config('services.sms_gateway.base_url', ''), '/');
        $token = (string) config('services.sms_gateway.token', '');
        $fromType = (string) config('services.sms_gateway.from_type', 'sender_id');
        $senderId = (string) config('services.sms_gateway.sender_id', '');
        $fromNumber = (string) config('services.sms_gateway.from_number', '');

        if ($baseUrl === '' || $token === '') {
            throw new NotConfiguredException(
                'Passerelle SMS non configurée. Renseignez SMS_GATEWAY_BASE_URL et '
                .'SMS_GATEWAY_TOKEN, ou repassez SMS_PROVIDER=fake.'
            );
        }

        if ($fromType === 'sender_id' && $senderId === '') {
            throw new NotConfiguredException(
                'SMS_GATEWAY_SENDER_ID est requis quand SMS_GATEWAY_FROM_TYPE=sender_id.'
            );
        }

        if ($fromType === 'phone_number' && $fromNumber === '') {
            throw new NotConfiguredException(
                'SMS_GATEWAY_FROM_NUMBER est requis quand SMS_GATEWAY_FROM_TYPE=phone_number.'
            );
        }

        $payload = [
            ['name' => 'from_type', 'contents' => $fromType],
            // La passerelle attend un tableau JSON par destinataire ; la forme
            // {"value": "+237..."} correspond à la saisie libre d'un numéro,
            // par opposition aux références de contact ou de groupe stockés
            // dans son propre carnet d'adresses.
            ['name' => 'to_numbers[]', 'contents' => json_encode(['value' => $phoneE164], JSON_THROW_ON_ERROR)],
            ['name' => 'body', 'contents' => $message],
        ];

        if ($fromType === 'sender_id') {
            $payload[] = ['name' => 'sender_id', 'contents' => $senderId];
        } else {
            $payload[] = ['name' => 'from_number', 'contents' => $fromNumber];
        }

        $response = Http::asMultipart()
            ->timeout(self::TIMEOUT_SECONDS)
            ->withToken($token)
            ->withHeaders(['Accept' => 'application/json'])
            ->post($baseUrl.'/sent/compose', $payload);

        if (! $response->successful()) {
            throw new RuntimeException(
                "Passerelle SMS HTTP {$response->status()} — SMS non envoyé."
            );
        }

        $status = $response->json('status');
        if ($status !== 'success') {
            $reason = $response->json('message');
            throw new RuntimeException(
                'La passerelle SMS a refusé l\'envoi (status: '.(is_string($status) ? $status : 'inconnu')
                .(is_string($reason) ? ", message: {$reason}" : '').').'
            );
        }

        // Ni le numéro complet ni le jeton ne doivent atterrir dans les logs.
        Log::channel('sms')->info('[gateway-api] SMS envoyé', [
            'phone' => PhoneMasker::mask($phoneE164),
            'length' => mb_strlen($message),
        ]);
    }
}
