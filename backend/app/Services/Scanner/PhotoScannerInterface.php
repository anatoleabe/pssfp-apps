<?php

declare(strict_types=1);

namespace App\Services\Scanner;

/**
 * Contrat minimal d'un scanner antivirus pour les pièces uploadées
 * (cf. spec module 5 §M2 et §13 R5).
 *
 * V1 : implémentation NoopPhotoScanner (toujours clean) en dev/test.
 * Prod : implémentation ClamAvPhotoScanner (socket clamd) à câbler dans
 * AppServiceProvider quand l'agent ClamAV est déployé sur le VPS Contabo.
 *
 * Le job ScanUploadedPhotoJob lit les bytes depuis MinIO, appelle scan(),
 * et purge le fichier + reset photo_path si infected.
 */
interface PhotoScannerInterface
{
    /**
     * Scanne le contenu binaire fourni. Retourne `true` si infecté.
     *
     * @param  string  $bytes  contenu binaire complet du fichier
     */
    public function isInfected(string $bytes): bool;
}
