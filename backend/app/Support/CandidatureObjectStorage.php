<?php

declare(strict_types=1);

namespace App\Support;

use Aws\S3\Exception\S3Exception;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToReadFile;
use Throwable;

/**
 * Lecture des objets du bucket privé `pssfp-candidatures`.
 *
 * Raison d'être : la clé de service MinIO de production autorise `GetObject`
 * mais **pas** `HeadObject`. Tout code qui testait `$disk->exists($path)` avant
 * de lire recevait un 403 et en concluait à tort que l'objet n'existait pas
 * (photos absentes des récépissés, scans antivirus jamais exécutés).
 *
 * On lit donc directement, et on ne traduit en « objet absent » que les erreurs
 * qui le disent vraiment. Toute autre panne (réseau, MinIO indisponible)
 * remonte pour que l'appelant — un job en file d'attente — puisse réessayer
 * au lieu de considérer le fichier comme sain.
 */
final class CandidatureObjectStorage
{
    public const DISK = 'minio_candidatures';

    /** Codes d'erreur S3/MinIO signifiant « la clé n'existe pas ». */
    private const MISSING_OBJECT_CODES = ['NoSuchKey', 'NotFound', 'NoSuchBucket'];

    /**
     * Retourne le contenu de l'objet, ou `null` s'il n'existe pas.
     *
     * @throws Throwable en cas de panne de stockage (à distinguer d'une absence)
     */
    public static function read(string $path): ?string
    {
        try {
            return (string) Storage::disk(self::DISK)->get($path);
        } catch (UnableToReadFile $e) {
            if (self::isMissingObject($e)) {
                return null;
            }

            throw $e;
        }
    }

    private static function isMissingObject(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof S3Exception) {
                return in_array($current->getAwsErrorCode(), self::MISSING_OBJECT_CODES, true)
                    || $current->getStatusCode() === 404;
            }
        }

        // Disques locaux / fakes de test : Flysystem lève UnableToReadFile sans
        // exception S3 sous-jacente — c'est bien une absence de fichier.
        return true;
    }
}
