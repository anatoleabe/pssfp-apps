<?php

declare(strict_types=1);

namespace App\Services\Library\Import;

use App\Models\Document;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Vérifications avant un import réel, et orphelins des imports interrompus.
 *
 *  - le type « rapport » est accepté par la base (migration du plan 3, task 1 passée) ;
 *  - chaque disque MinIO accepte, rend et supprime un objet témoin (put/get/delete, jamais HeadObject).
 */
final class ImportPreflight
{
    public const RAPPORT_MIGRATION = '2026_10_18_110000_add_rapport_to_documents_type_check';

    private const PROBE_DIRECTORY = 'import-probe';

    /** @throws RuntimeException message lisible, rien n'a été importé */
    public static function check(): void
    {
        if (! DB::table('migrations')->where('migration', self::RAPPORT_MIGRATION)->exists()) {
            throw new RuntimeException('Préalable non rempli : la migration du type « rapport » ('.self::RAPPORT_MIGRATION.') n\'est pas passée (php artisan migrate).');
        }

        foreach (array_unique([(string) config('library.documents_disk'), (string) config('library.covers_disk')]) as $disk) {
            self::probe($disk);
        }
    }

    /**
     * Objets journalisés `envoi` qu'aucun document ne référence : signalés, supprimés sur demande.
     */
    public static function orphans(string $directory, ImportJournal $journal, ImportReport $report, bool $clean): void
    {
        foreach (ImportJournal::pending($directory) as $entry) {
            $referenced = Document::withTrashed()
                ->where(fn ($q) => $q->where('file_path', $entry['key'])->orWhere('cover_path', $entry['key']))
                ->exists();
            if ($referenced) {
                continue;
            }

            $report->orphan($entry);
            if ($clean) {
                if (Storage::disk($entry['disk'])->delete($entry['key'])) {
                    $journal->deleted($entry['disk'], $entry['key'], $entry['source'], $entry['id']);
                    $report->orphanCleaned();
                } else {
                    $report->cleanupFailure($entry['disk'], $entry['key']);
                }
            }
        }
    }

    private static function probe(string $disk): void
    {
        $key = self::PROBE_DIRECTORY.'/'.Str::uuid().'.txt';
        $content = 'biblio:import '.now()->toIso8601String();

        try {
            $storage = Storage::disk($disk);
            $ok = $storage->put($key, $content) === true
                && $storage->get($key) === $content
                && $storage->delete($key) === true;
        } catch (Throwable) {
            $ok = false;
        }

        if (! $ok) {
            throw new RuntimeException("Préalable non rempli : le disque {$disk} n'accepte pas l'objet témoin {$key} (écriture, lecture, suppression).");
        }
    }
}
