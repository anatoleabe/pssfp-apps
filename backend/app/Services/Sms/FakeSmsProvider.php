<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Support\PhoneMasker;
use Illuminate\Support\Facades\Log;

/**
 * Provider SMS factice : log uniquement, aucun appel réseau.
 *
 * Utilisé en dev local et dans les tests. La validation de l'envoi se fait
 * via Log::shouldReceive(...) ou en lisant le channel `sms` configuré dans
 * config/logging.php.
 */
final class FakeSmsProvider implements ReportsSmsDelivery, SmsServiceInterface
{
    public function send(string $phoneE164, string $message): void
    {
        $this->sendAndReport($phoneE164, $message);
    }

    public function sendAndReport(string $phoneE164, string $message): SmsSendResult
    {
        Log::channel('sms')->info('[fake-sms] Outgoing message', [
            // Masqué même en dev : ces logs finissent en pièce jointe de
            // ticket, et un numéro de candidat reste une donnée personnelle.
            'phone' => PhoneMasker::mask($phoneE164),
            'message' => $message,
        ]);

        // `fake` se décrit comme tel : un journal d'envoi ne doit jamais
        // laisser croire qu'un SMS est réellement parti en développement.
        return new SmsSendResult(expediteur: 'fake', codeFournisseur: null);
    }
}
