<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Candidature;
use App\Services\Scanner\PhotoScannerInterface;
use App\Support\CandidatureObjectStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Scan asynchrone d'une photo candidat uploadée (cf. spec module 5 PR G).
 *
 * - Lit les bytes depuis le bucket pssfp-candidatures.
 * - Appelle le scanner (ClamAV en prod, Noop en dev/test).
 * - Si infected : supprime le fichier + reset photo_path + log activité.
 * - Si clean : ne fait rien (le path reste valide).
 *
 * Le candidat voit pendant ce délai la photo « en cours de validation »
 * (cf. PhotoUploader frontend).
 */
final class ScanUploadedPhotoJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Nombre max de tentatives en cas d'échec réseau MinIO. */
    public int $tries = 3;

    public function __construct(
        public readonly string $candidatureUuid,
        public readonly string $path,
    ) {}

    public function handle(PhotoScannerInterface $scanner): void
    {
        $bytes = CandidatureObjectStorage::read($this->path);

        // null = objet déjà supprimé ou remplacé entre l'upload et le scan.
        if ($bytes === null || ! $scanner->isInfected($bytes)) {
            return;
        }

        Storage::disk(CandidatureObjectStorage::DISK)->delete($this->path);

        $candidature = Candidature::query()->where('uuid', $this->candidatureUuid)->first();
        if ($candidature !== null && $candidature->photo_path === $this->path) {
            $candidature->update(['photo_path' => null]);

            activity('candidatures')
                ->performedOn($candidature)
                ->withProperties([
                    'path' => $this->path,
                    'reason' => 'antivirus_infected',
                ])
                ->event('candidate_photo_quarantined')
                ->log('Photo candidat supprimée (scan antivirus positif)');
        }

        Log::warning('candidate_photo_infected', [
            'uuid' => $this->candidatureUuid,
            'path' => $this->path,
        ]);
    }
}
