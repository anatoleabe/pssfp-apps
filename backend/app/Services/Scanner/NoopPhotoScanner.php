<?php

declare(strict_types=1);

namespace App\Services\Scanner;

/**
 * Implémentation par défaut quand aucun ClamAV n'est dispo (dev local + tests).
 *
 * Ne jamais utiliser en production : binder ClamAvPhotoScanner à la place
 * dans AppServiceProvider quand l'agent est déployé.
 */
final class NoopPhotoScanner implements PhotoScannerInterface
{
    public function isInfected(string $bytes): bool
    {
        // Garde-fou : la signature EICAR standard est détectée en dev pour
        // permettre les tests automatisés sans daemon ClamAV.
        $eicar = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

        return str_contains($bytes, $eicar);
    }
}
