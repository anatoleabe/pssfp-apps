<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * Contrat optionnel : une passerelle capable de décrire sa configuration.
 *
 * Même raison d'être que ReportsSmsDelivery — élargir SmsServiceInterface
 * obligerait chaque implémentation à changer de signature pour un besoin
 * qui ne concerne que l'affichage. Les appelants testent `instanceof`.
 */
interface DescribesConfiguration
{
    public function decrire(): SmsConfigurationSummary;
}
