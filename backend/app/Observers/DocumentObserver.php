<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\ProcessDocumentFile;
use App\Models\Document;

/** Relance le traitement du PDF à chaque nouveau fichier. */
final class DocumentObserver
{
    public function saved(Document $document): void
    {
        $nouveauFichier = $document->wasRecentlyCreated || $document->wasChanged('file_path');

        if ($document->file_path !== null && $nouveauFichier) {
            ProcessDocumentFile::dispatch($document->id)->afterCommit();
        }
    }
}
