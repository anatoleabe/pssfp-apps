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
use RuntimeException;

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

    /**
     * Sous le `retry_after` de la file Redis (90 s, config/queue.php) : au-delà,
     * le job serait relancé alors qu'il tourne encore. pdftoppm est borné à 60 s.
     */
    public int $timeout = 85;

    public function __construct(public readonly int $documentId) {}

    public function handle(CoverRenderer $renderer): void
    {
        $document = Document::query()->find($this->documentId);
        if ($document === null || $document->file_path === null) {
            return;
        }

        $local = tempnam(sys_get_temp_dir(), 'doc');
        if ($local === false) {
            throw new RuntimeException('Impossible de créer le fichier temporaire.');
        }

        $stream = null;
        $out = null;

        try {
            $stream = Storage::disk((string) config('library.documents_disk'))->readStream($document->file_path);
            if ($stream === null || $stream === false) {
                // Disque configuré avec throw=false : fichier absent ou MinIO injoignable.
                // On journalise sans faire échouer la file (l'import le signalera dans son rapport).
                Log::warning('Bibliothèque : PDF illisible', ['document' => $document->uuid, 'path' => $document->file_path]);

                return;
            }

            $out = fopen($local, 'wb');
            if ($out === false) {
                throw new RuntimeException('Impossible d\'ouvrir le fichier temporaire en écriture.');
            }
            if (stream_copy_to_stream($stream, $out) === false) {
                throw new RuntimeException('Copie du PDF interrompue.');
            }
            if (! fclose($out)) {
                $out = null;
                throw new RuntimeException('Écriture du fichier temporaire incomplète.');
            }
            $out = null;

            $attributes = [
                'file_size_bytes' => filesize($local),
                'file_checksum_md5' => md5_file($local),
                'cover_path' => null,
            ];

            $jpeg = $renderer->render($local);
            if ($jpeg !== null) {
                $coverPath = (string) config('library.covers_directory')."/{$document->uuid}.jpg";
                Storage::disk((string) config('library.covers_disk'))->put($coverPath, $jpeg, ['visibility' => 'public']);
                $attributes['cover_path'] = $coverPath;
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (is_resource($out)) {
                fclose($out);
            }
            @unlink($local);
        }

        Document::withoutEvents(fn () => $document->forceFill($attributes)->save());

        // searchable() seul ne consulte pas shouldBeSearchable() : sans ce garde,
        // un brouillon avec PDF partirait dans l'index public.
        $document->syncSearchIndex();
    }
}
