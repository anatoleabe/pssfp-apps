<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * Contrat optionnel : une passerelle capable de confirmer qu'on l'atteint.
 *
 * `verifierConnexion()` ne lève JAMAIS : un diagnostic doit pouvoir dire
 * « injoignable » sans faire tomber la page qui l'affiche.
 */
interface ChecksConnectivity
{
    public function verifierConnexion(): ConnectivityReport;
}
