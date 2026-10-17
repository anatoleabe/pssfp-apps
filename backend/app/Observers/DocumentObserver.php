<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\ProcessDocumentFile;
use App\Models\Document;

/** Relance le traitement du PDF à chaque nouveau fichier. */
final class DocumentObserver
{
    public function created(Document $document): void
    {
        if ($document->file_path !== null) {
            ProcessDocumentFile::dispatch($document->id)->afterCommit();
        }
    }

    public function updated(Document $document): void
    {
        if ($document->file_path !== null && $document->wasChanged('file_path')) {
            ProcessDocumentFile::dispatch($document->id)->afterCommit();
        }
    }
}
