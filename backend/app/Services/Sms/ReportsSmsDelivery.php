<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * Contrat optionnel : une passerelle capable de dire ce qu'elle a fait.
 *
 * Volontairement séparé de SmsServiceInterface. Élargir l'interface
 * principale obligerait chaque implémentation existante à changer de
 * signature — y compris le stub AfricasTalking — pour un besoin qui ne
 * concerne que la traçabilité. Les appelants testent `instanceof` et
 * retombent sur `send()` quand la passerelle ne sait pas se décrire.
 */
interface ReportsSmsDelivery
{
    /**
     * Envoie le message et décrit l'envoi.
     *
     * Mêmes exceptions que `send()` : un échec reste une exception, jamais
     * un résultat silencieux.
     */
    public function sendAndReport(string $phoneE164, string $message): SmsSendResult;
}
