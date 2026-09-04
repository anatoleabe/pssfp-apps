<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Exceptions\NotConfiguredException;
use App\Support\PhoneMasker;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Passerelle SMS Echo SMS (https://account.echosms.io).
 *
 * Contrat réel, vérifié contre l'API de production le 3 septembre 2026 :
 *
 * - `POST {base}/sent/compose?api_key=...` — la clé passe en QUERY, pas en
 *   en-tête. Un Bearer sur cet endpoint répond `{"response":"1003"}`
 *   (« API Not Found »), alors qu'il est accepté sur d'autres routes comme
 *   /sent/list. L'API mélange deux schémas d'authentification.
 * - Corps en `application/x-www-form-urlencoded` : from_type, sender_id
 *   (ou from_number), to_numbers, body.
 * - `to_numbers` accepte plusieurs destinataires séparés par des virgules.
 *   On n'envoie qu'un numéro à la fois : un refus partiel sur un lot serait
 *   impossible à attribuer, et la traçabilité par candidat y perdrait.
 * - Réponse : `{"response":"<code>"}`. 1016 = envoyé. Le code HTTP est 200
 *   y compris sur un refus métier — c'est le code applicatif qui fait foi.
 *
 * La clé transitant en query, elle apparaîtrait dans les traces d'exception
 * incluant l'URL : aucun message d'erreur construit ici ne contient l'URL.
 */
final class EchoSmsProvider implements ReportsSmsDelivery, SmsServiceInterface
{
    private const TIMEOUT_SECONDS = 20;

    private const CODE_ENVOYE = '1016';

    private const CODE_ENVOI_PARTIEL = '1015';

    /** @var array<string, string> */
    private const MESSAGES = [
        '1001' => 'Utilisateur introuvable',
        '1002' => 'Sender ID / masking invalide',
        '1003' => 'API introuvable (mauvais endpoint ou authentification refusée)',
        '1004' => 'Numéro WhatsApp invalide',
        '1005' => 'Numéro émetteur invalide',
        '1007' => 'Solde insuffisant',
        '1008' => 'Message vide',
        '1009' => 'Type de message non défini',
        '1010' => 'Numéro invalide',
        '1011' => 'Crédit insuffisant',
        '1013' => 'Numéro introuvable, contacter l\'administrateur',
        '1014' => 'Passerelle SMS non configurée côté Echo SMS',
        '1015' => 'Message envoyé partiellement',
        '1016' => 'Message envoyé',
        '1017' => 'Aucun forfait actif sur le compte',
        '1018' => 'OTP non activé',
        '1019' => 'Fournisseur inactif, contacter l\'administrateur',
        '1020' => 'Identifiants du fournisseur non configurés',
        '1021' => 'Modèle introuvable',
    ];

    public function send(string $phoneE164, string $message): void
    {
        $this->sendAndReport($phoneE164, $message);
    }

    public function sendAndReport(string $phoneE164, string $message): SmsSendResult
    {
        $baseUrl = rtrim((string) config('services.echosms.base_url', ''), '/');
        $apiKey = (string) config('services.echosms.api_key', '');
        $fromType = (string) config('services.echosms.from_type', 'sender_id');
        $senderId = (string) config('services.echosms.sender_id', '');
        $fromNumber = (string) config('services.echosms.from_number', '');

        if ($baseUrl === '' || $apiKey === '') {
            throw new NotConfiguredException(
                'Echo SMS non configuré. Renseignez ECHOSMS_BASE_URL et ECHOSMS_API_KEY, '
                .'ou repassez SMS_PROVIDER=fake.'
            );
        }

        if ($fromType === 'sender_id' && $senderId === '') {
            throw new NotConfiguredException(
                'ECHOSMS_SENDER_ID est requis quand ECHOSMS_FROM_TYPE=sender_id.'
            );
        }

        if ($fromType === 'phone_number' && $fromNumber === '') {
            throw new NotConfiguredException(
                'ECHOSMS_FROM_NUMBER est requis quand ECHOSMS_FROM_TYPE=phone_number.'
            );
        }

        $parametres = [
            'from_type' => $fromType,
            'to_numbers' => $phoneE164,
            'body' => $message,
        ];
        $parametres[$fromType === 'sender_id' ? 'sender_id' : 'from_number'] =
            $fromType === 'sender_id' ? $senderId : $fromNumber;

        $response = Http::asForm()
            ->timeout(self::TIMEOUT_SECONDS)
            ->withHeaders(['Accept' => 'application/json'])
            // La clé reste dans l'URL car l'API l'exige ainsi ; elle n'est
            // jamais reprise dans un message d'erreur ni dans un log.
            ->post($baseUrl.'/sent/compose?api_key='.urlencode($apiKey), $parametres);

        if (! $response->successful()) {
            throw new RuntimeException(
                "Echo SMS a répondu HTTP {$response->status()} — SMS non envoyé."
            );
        }

        $code = $response->json('response');
        $code = is_scalar($code) ? (string) $code : null;

        if ($code === null) {
            // Erreur de validation Laravel côté Echo SMS : le corps est alors
            // {"message":{"champ":["..."]}} et non {"response":"code"}.
            $detail = $response->json('message');
            throw new RuntimeException(
                'Echo SMS a refusé la requête : '
                .(is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE))
            );
        }

        if ($code !== self::CODE_ENVOYE && $code !== self::CODE_ENVOI_PARTIEL) {
            throw new RuntimeException(
                'Echo SMS a refusé l\'envoi (code '.$code.' : '
                .(self::MESSAGES[$code] ?? 'motif inconnu').').'
            );
        }

        Log::channel('sms')->info('[echosms] SMS envoyé', [
            'phone' => PhoneMasker::mask($phoneE164),
            'code' => $code,
            'partiel' => $code === self::CODE_ENVOI_PARTIEL,
            'length' => mb_strlen($message),
        ]);

        return new SmsSendResult(
            expediteur: $fromType === 'sender_id' ? $senderId : $fromNumber,
            codeFournisseur: $code,
            partiel: $code === self::CODE_ENVOI_PARTIEL,
        );
    }
}
