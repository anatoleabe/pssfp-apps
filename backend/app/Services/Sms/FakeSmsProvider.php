<?php

declare(strict_types=1);

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Provider SMS factice : log uniquement, aucun appel réseau.
 *
 * Utilisé en dev local et dans les tests. La validation de l'envoi se fait
 * via Log::shouldReceive(...) ou en lisant le channel `sms` configuré dans
 * config/logging.php.
 */
final class FakeSmsProvider implements SmsServiceInterface
{
    public function send(string $phoneE164, string $message): void
    {
        Log::channel('sms')->info('[fake-sms] Outgoing message', [
            'phone' => $phoneE164,
            'message' => $message,
        ]);
    }
}
