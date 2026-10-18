<?php

declare(strict_types=1);

namespace App\Services\Library\Import;

use RuntimeException;

/**
 * Journal des objets MinIO de biblio:import (storage/logs/biblio-import-{date}.journal, 0600).
 *
 * Chaque objet est inscrit (`envoi`) AVANT son envoi, puis marqué `valide` après la validation
 * en base de son document, ou `supprime` quand l'import l'a retiré. Après un arrêt brutal
 * (processus tué entre l'envoi et la validation), les objets restés `envoi` sans document qui
 * les référence sont des orphelins : signalés à la relance, supprimés avec --clean-orphans.
 *
 * Format d'une ligne : `{état}\t{disque}\t{clé}\t{source}\t{id}`.
 */
final class ImportJournal
{
    public const SENT = 'envoi';

    public const COMMITTED = 'valide';

    public const DELETED = 'supprime';

    private const PATTERN = 'biblio-import-*.journal';

    public function __construct(public readonly string $path) {}

    public static function inDirectory(string $directory, string $stamp): self
    {
        return new self(rtrim($directory, '/')."/biblio-import-{$stamp}.journal");
    }

    public function sent(string $disk, string $key, string $source, int $id): void
    {
        $this->append(self::SENT, $disk, $key, $source, $id);
    }

    public function committed(string $disk, string $key, string $source, int $id): void
    {
        $this->append(self::COMMITTED, $disk, $key, $source, $id);
    }

    public function deleted(string $disk, string $key, string $source, int $id): void
    {
        $this->append(self::DELETED, $disk, $key, $source, $id);
    }

    /**
     * Objets inscrits `envoi` et jamais `valide` ni `supprime`, tous journaux du dossier confondus.
     *
     * @return list<array{disk: string, key: string, source: string, id: int}>
     */
    public static function pending(string $directory): array
    {
        $state = [];
        foreach (glob(rtrim($directory, '/').'/'.self::PATTERN) ?: [] as $journal) {
            foreach (file($journal, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $fields = explode("\t", $line);
                if (count($fields) !== 5) {
                    continue;
                }
                [$status, $disk, $key, $source, $id] = $fields;
                $state[$disk."\t".$key] = ['status' => $status, 'disk' => $disk, 'key' => $key, 'source' => $source, 'id' => (int) $id];
            }
        }

        $pending = [];
        foreach ($state as $entry) {
            if ($entry['status'] === self::SENT) {
                unset($entry['status']);
                $pending[] = $entry;
            }
        }

        return $pending;
    }

    private function append(string $status, string $disk, string $key, string $source, int $id): void
    {
        $line = implode("\t", [$status, $disk, $key, $source, (string) $id])."\n";

        $umask = umask(0077);
        try {
            $written = file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
        } finally {
            umask($umask);
        }

        if ($written === false) {
            // Sans journal, un arrêt brutal laisserait des objets introuvables : on n'envoie rien.
            throw new RuntimeException("Écriture impossible du journal d'import : {$this->path}");
        }
    }
}
