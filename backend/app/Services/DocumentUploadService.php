<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ScanUploadedDocumentJob;
use App\Models\Candidature;
use App\Models\CandidatureDocument;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Pièces justificatives optionnelles du dossier candidature (diplôme, acte
 * de naissance, relevés de notes, CV, lettre de motivation, attestation
 * employeur). Cf. migration `create_candidature_documents_table` pour le
 * raisonnement produit — non-bloquant, dépôt physique alternatif possible.
 *
 * Plusieurs fichiers par type autorisés (ex. 3 relevés L1/L2/L3 distincts).
 */
final class DocumentUploadService
{
    private const SIGNED_URL_TTL_MINUTES = 30;

    private const DISK = 'minio_candidatures';

    public function upload(UploadedFile $file, Candidature $candidature, string $type): CandidatureDocument
    {
        $extension = match ($file->getMimeType()) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => throw new RuntimeException('Unsupported MIME type: '.$file->getMimeType()),
        };

        $path = 'candidat-documents/'.$candidature->uuid.'/'.Str::uuid()->toString().'.'.$extension;

        $this->disk()->putFileAs(
            dirname($path),
            $file,
            basename($path),
            ['visibility' => 'private', 'ContentType' => $file->getMimeType()],
        );

        $document = $candidature->documents()->create([
            'type' => $type,
            'original_filename' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'path' => $path,
        ]);

        activity('candidatures')
            ->causedBy(auth()->user())
            ->performedOn($candidature)
            ->withProperties([
                'ip' => request()?->ip(),
                'document_uuid' => $document->uuid,
                'type' => $type,
                'mime' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
            ])
            ->event('candidate_document_uploaded')
            ->log('Pièce justificative candidat uploadée');

        ScanUploadedDocumentJob::dispatch($document->uuid, $path);

        return $document;
    }

    public function delete(CandidatureDocument $document): void
    {
        $this->disk()->delete($document->path);

        activity('candidatures')
            ->causedBy(auth()->user())
            ->performedOn($document->candidature)
            ->withProperties([
                'ip' => request()?->ip(),
                'document_uuid' => $document->uuid,
                'type' => $document->type,
            ])
            ->event('candidate_document_deleted')
            ->log('Pièce justificative candidat supprimée');

        $document->delete();
    }

    public function signedUrl(CandidatureDocument $document): string
    {
        $disk = $this->disk();

        if (method_exists($disk, 'temporaryUrl')) {
            return $disk->temporaryUrl($document->path, now()->addMinutes(self::SIGNED_URL_TTL_MINUTES));
        }

        if (! app()->environment('testing')) {
            Log::error('document_signed_url_unsupported_driver', ['disk' => self::DISK]);
        }

        return 'fake://'.$document->path;
    }

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
