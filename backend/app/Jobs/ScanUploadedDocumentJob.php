<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CandidatureDocument;
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
 * Scan antivirus asynchrone d'une pièce justificative uploadée — même
 * scanner que la photo (ClamAV en prod, Noop en dev/test — cf. spec PR G).
 *
 * Si infecté : supprime le fichier + l'enregistrement CandidatureDocument.
 */
final class ScanUploadedDocumentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $documentUuid,
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

        $document = CandidatureDocument::query()->where('uuid', $this->documentUuid)->first();
        if ($document !== null) {
            activity('candidatures')
                ->performedOn($document->candidature)
                ->withProperties([
                    'document_uuid' => $document->uuid,
                    'path' => $this->path,
                    'reason' => 'antivirus_infected',
                ])
                ->event('candidate_document_quarantined')
                ->log('Pièce justificative supprimée (scan antivirus positif)');

            $document->delete();
        }

        Log::warning('candidate_document_infected', [
            'document_uuid' => $this->documentUuid,
            'path' => $this->path,
        ]);
    }
}
