<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Document;
use App\Services\Library\Files\CoverRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Après dépôt ou import d'un PDF : taille, MD5 et couverture.
 *
 * Lecture par readStream (GetObject) uniquement — pas de HeadObject en prod.
 */
final class ProcessDocumentFile implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(public readonly int $documentId) {}

    public function handle(CoverRenderer $renderer): void
    {
        $document = Document::query()->find($this->documentId);
        if ($document === null || $document->file_path === null) {
            return;
        }

        $local = tempnam(sys_get_temp_dir(), 'doc');
        $stream = Storage::disk((string) config('library.documents_disk'))->readStream($document->file_path);
        if ($stream === null) {
            // Disque configuré avec throw=false : fichier absent ou MinIO injoignable.
            // On journalise sans faire échouer la file (l'import le signalera dans son rapport).
            @unlink($local);
            Log::warning('Bibliothèque : PDF illisible', ['document' => $document->uuid, 'path' => $document->file_path]);

            return;
        }
        $out = fopen($local, 'wb');
        stream_copy_to_stream($stream, $out);
        fclose($out);
        fclose($stream);

        $attributes = [
            'file_size_bytes' => filesize($local),
            'file_checksum_md5' => md5_file($local),
        ];

        $jpeg = $renderer->render($local);
        if ($jpeg !== null) {
            $coverPath = config('library.covers_directory')."/{$document->uuid}.jpg";
            Storage::disk((string) config('library.covers_disk'))->put($coverPath, $jpeg, ['visibility' => 'public']);
            $attributes['cover_path'] = $coverPath;
        }

        @unlink($local);

        Document::withoutEvents(fn () => $document->forceFill($attributes)->save());
        $document->searchable();
    }
}
