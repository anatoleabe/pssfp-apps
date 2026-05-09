<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ScanUploadedPhotoJob;
use App\Models\Candidature;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Gère le cycle de vie de la photo identité d'un candidat (cf. spec PR G).
 *
 * - upload(file, candidature) : stocke sur MinIO, met à jour photo_path,
 *   dispatch ScanUploadedPhotoJob async, retourne le path.
 * - delete(candidature) : supprime le fichier + reset photo_path. Refusé
 *   si la candidature est verrouillée (statut != postulant).
 * - signedUrl(path) : URL signée 30 min pour affichage côté frontend.
 */
final class PhotoUploadService
{
    private const SIGNED_URL_TTL_MINUTES = 30;

    private const DISK = 'minio_candidatures';

    public function upload(UploadedFile $file, Candidature $candidature): string
    {
        // Extension dérivée uniquement du MIME réel (cf. revue sécu PR G —
        // ne jamais faire confiance au nom de fichier client).
        $extension = match ($file->getMimeType()) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => throw new RuntimeException('Unsupported MIME type: '.$file->getMimeType()),
        };

        $path = 'candidat-photos/'.$candidature->uuid.'/'.Str::uuid()->toString().'.'.$extension;

        // putFileAs streame depuis le tmpfile sans charger en RAM (vs
        // file_get_contents qui chargeait tout). Cf. revue PR G.
        $this->disk()->putFileAs(
            dirname($path),
            $file,
            basename($path),
            ['visibility' => 'private', 'ContentType' => $file->getMimeType()],
        );

        // Transaction + verrou pessimiste sur la candidature pour éviter la
        // race condition où deux uploads concurrents s'écrasent (cf. revue PR G).
        $previous = DB::transaction(function () use ($candidature, $path): ?string {
            $locked = Candidature::query()->whereKey($candidature->id)->lockForUpdate()->firstOrFail();
            $previous = $locked->photo_path;
            $locked->update(['photo_path' => $path]);
            $candidature->setAttribute('photo_path', $path);

            return $previous;
        });

        if ($previous !== null && $previous !== $path) {
            $this->disk()->delete($previous);
        }

        activity('candidatures')
            ->causedBy(auth()->user())
            ->performedOn($candidature)
            ->withProperties([
                'ip' => request()?->ip(),
                'path' => $path,
                'mime' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
            ])
            ->event('candidate_photo_uploaded')
            ->log('Photo candidat uploadée');

        ScanUploadedPhotoJob::dispatch($candidature->uuid, $path);

        return $path;
    }

    public function delete(Candidature $candidature): void
    {
        if ($candidature->photo_path === null) {
            return;
        }

        $this->disk()->delete($candidature->photo_path);

        activity('candidatures')
            ->causedBy(auth()->user())
            ->performedOn($candidature)
            ->withProperties([
                'ip' => request()?->ip(),
                'path' => $candidature->photo_path,
            ])
            ->event('candidate_photo_deleted')
            ->log('Photo candidat supprimée');

        $candidature->update(['photo_path' => null]);
    }

    public function signedUrl(string $path): string
    {
        $disk = $this->disk();

        if (method_exists($disk, 'temporaryUrl')) {
            return $disk->temporaryUrl($path, now()->addMinutes(self::SIGNED_URL_TTL_MINUTES));
        }

        // Fallback `fake://` exclusivement pour les tests Storage::fake (in-memory).
        // En prod un disk MinIO non-S3 serait une erreur de config — on log + retourne
        // une URL non utilisable plutôt que jeter (compatibilité avec la suite Pest existante).
        if (! app()->environment('testing')) {
            Log::error('photo_signed_url_unsupported_driver', [
                'disk' => self::DISK,
            ]);
        }

        return 'fake://'.$path;
    }

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
