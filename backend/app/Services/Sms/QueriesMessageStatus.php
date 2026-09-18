<?php

declare(strict_types=1);

namespace App\Services\Sms;

use RuntimeException;

/**
 * Contrat optionnel : une passerelle qui sait dire ce qu'est devenu un
 * message, à partir de l'identifiant qu'elle a rendu à l'envoi.
 */
interface QueriesMessageStatus
{
    /** @throws RuntimeException si la passerelle refuse ou ignore l'identifiant. */
    public function statutMessage(string $uid): MessageStatus;
}
