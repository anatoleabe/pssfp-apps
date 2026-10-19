<?php

declare(strict_types=1);

namespace App\Services\Library\Import;

use App\Jobs\ProcessDocumentFile;
use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Fichier d'un document importé (biblio:import, biblio:import-orphans) : envoi sur MinIO,
 * couverture, journal des objets envoyés et retrait de ces objets si l'élément échoue.
 *
 * Chaque objet est inscrit au journal (`envoi`) avant son envoi ; une fois le document validé en
 * base, committed() le marque `valide` ; discard() le retire de MinIO (`supprime`). Uniquement
 * writeStream/put/delete : jamais exists()/size()/mimeType() (HeadObject interdit en prod).
 */
final class ImportedFileStore
{
    /** @var list<array{0: string, 1: string}> objets MinIO envoyés pour l'élément en cours */
    private array $uploaded = [];

    private string $source = '';

    private int $id = 0;

    public function __construct(private readonly ?ImportJournal $journal) {}

    /** Début d'un élément : ses objets seront validés ou retirés avec lui. */
    public function begin(string $source, int $id): void
    {
        $this->source = $source;
        $this->id = $id;
        $this->uploaded = [];
    }

    /**
     * Fichier sur MinIO (writeStream, retour vérifié), puis couverture : l'image fournie si elle
     * existe, sinon générée par ProcessDocumentFile, exécuté ici de façon synchrone (le fichier est
     * enregistré sans événement pour que l'observateur ne le mette pas aussi en file).
     *
     * @param  array{path: string, extension: string}|null  $cover
     * @return 'copied'|'generated'|'failed' issue de la couverture
     */
    public function store(Document $document, string $localPath, string $md5, string $extension, ?array $cover = null): string
    {
        $key = 'documents/'.$document->uuid.'.'.$extension;
        $this->upload((string) config('library.documents_disk'), $key, $localPath, []);

        $coverPath = null;
        if ($cover !== null) {
            $coverPath = config('library.covers_directory').'/'.$document->uuid.'.'.$cover['extension'];
            $this->upload((string) config('library.covers_disk'), $coverPath, $cover['path'], ['visibility' => 'public']);
        }

        $document->forceFill([
            'file_path' => $key,
            'file_size_bytes' => filesize($localPath) ?: null,
            'file_checksum_md5' => $md5,
            'cover_path' => $coverPath,
        ])->saveQuietly();

        if ($coverPath !== null) {
            return 'copied';
        }
        // Couverture générée par le job : journalisée d'avance, retirée si l'élément échoue.
        $disk = (string) config('library.covers_disk');
        $generated = config('library.covers_directory').'/'.$document->uuid.'.jpg';
        $this->journal?->sent($disk, $generated, $this->source, $this->id);
        $this->uploaded[] = [$disk, $generated];

        ProcessDocumentFile::dispatchSync($document->id);

        return $document->refresh()->cover_path === null ? 'failed' : 'generated';
    }

    /** Document validé en base : ses objets ne sont plus des orphelins potentiels. */
    public function committed(): void
    {
        foreach ($this->uploaded as [$disk, $key]) {
            $this->journal?->committed($disk, $key, $this->source, $this->id);
        }
        $this->uploaded = [];
    }

    /**
     * Élément en échec : ses objets sont retirés de MinIO.
     *
     * @return list<array{0: string, 1: string}> objets qui n'ont pas pu être supprimés (disque, clé)
     */
    public function discard(): array
    {
        $failures = [];
        foreach ($this->uploaded as [$disk, $key]) {
            if (Storage::disk($disk)->delete($key)) {
                $this->journal?->deleted($disk, $key, $this->source, $this->id);
            } else {
                // Reste « envoi » au journal : signalé comme orphelin à la prochaine relance.
                $failures[] = [$disk, $key];
            }
        }
        $this->uploaded = [];

        return $failures;
    }

    /** @param  array<string, mixed>  $options */
    private function upload(string $disk, string $key, string $localPath, array $options): void
    {
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException("Lecture impossible : {$localPath}");
        }
        // Inscrit avant l'envoi : un arrêt brutal entre l'envoi et la validation laisse une trace.
        $this->journal?->sent($disk, $key, $this->source, $this->id);
        $this->uploaded[] = [$disk, $key];

        try {
            // Disques MinIO en throw=false : le booléen renvoyé est la seule preuve de l'envoi.
            $written = Storage::disk($disk)->writeStream($key, $stream, $options);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($written !== true) {
            throw new RuntimeException("Envoi impossible vers le disque {$disk} : {$key}");
        }
    }
}
