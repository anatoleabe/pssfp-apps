<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Exceptions\NotConfiguredException;

/**
 * Contrat unifié pour les passerelles SMS du projet.
 *
 * Implémentations :
 * - FakeSmsProvider       : log uniquement, pas d'appel réseau (dev/test).
 * - AfricasTalkingProvider: stub V1, vraie intégration en Phase prod.
 *
 * Bind dans AppServiceProvider selon `services.sms.provider` (env SMS_PROVIDER).
 */
interface SmsServiceInterface
{
    /**
     * Envoie un SMS au numéro indiqué (E.164 obligatoire, ex: +237691234567).
     *
     * @throws NotConfiguredException si la passerelle n'est pas configurée.
     */
    public function send(string $phoneE164, string $message): void;
}
