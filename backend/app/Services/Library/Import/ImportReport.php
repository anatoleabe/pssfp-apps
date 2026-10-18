<?php

declare(strict_types=1);

namespace App\Services\Library\Import;

use Illuminate\Support\Carbon;

/**
 * Rapport de biblio:import (console + storage/logs/biblio-import-{date}.json). Identique en
 * dry-run : « créés » se lit alors « seraient créés ».
 */
final class ImportReport
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const UNCHANGED = 'unchanged';

    public const MERGED = 'merged';

    public const SKIPPED = 'skipped';

    public const MISSING_FILE = 'missing_files';

    public const ERROR = 'errors';

    private const OUTCOMES = [self::CREATED, self::UPDATED, self::UNCHANGED, self::MERGED, self::SKIPPED, self::MISSING_FILE, self::ERROR];

    /** @var array<string, array<string, int>> */
    private array $sources = [];

    /** @var array<string, array<string, int>> */
    private array $createdByType = [];

    /** @var array<string, int> */
    private array $createdByStatus = [];

    /** @var array<string, int> */
    private array $draftReasons = [];

    /** @var array<string, int> */
    private array $files = ['exact' => 0, 'tolerant' => 0];

    /** @var array<string, int> */
    private array $covers = ['copied' => 0, 'generated' => 0, 'failed' => 0, 'image_missing' => 0, 'image_invalid' => 0];

    /** @var array{created: int, existing: int, conflicts: int, discarded: int, by_form: array<string, array<string, int>>} */
    private array $redirects = ['created' => 0, 'existing' => 0, 'conflicts' => 0, 'discarded' => 0, 'by_form' => []];

    /** @var array<string, list<array<string, mixed>>> */
    private array $details = [
        'skipped' => [], 'missing_files' => [], 'merged' => [], 'merged_into_draft' => [], 'drafts' => [],
        'non_pdf_files' => [], 'cover_failures' => [], 'tolerant_matches' => [], 'redirect_conflicts' => [],
        'orphans' => [], 'cleanup_failures' => [], 'errors' => [],
    ];

    private int $orphansCleaned = 0;

    private ?string $fatal = null;

    /** @var array<string, mixed>|null état avant l'élément en cours */
    private ?array $checkpoint = null;

    private int $keywordsCreated = 0;

    private bool $systemUserCreated = false;

    /** @var array<string, int> */
    private array $acronymCandidates = [];

    private readonly string $startedAt;

    /** @param  array<string, mixed>  $options */
    public function __construct(public readonly bool $dryRun, private readonly array $options = [])
    {
        $this->startedAt = Carbon::now()->utc()->toIso8601String();
        foreach (ImportOptions::SOURCES as $source) {
            $this->sources[$source] = array_fill_keys(self::OUTCOMES, 0);
        }
    }

    /** Début d'un élément : ses compteurs seront annulés avec lui s'il échoue. */
    public function begin(): void
    {
        $this->checkpoint = $this->state();
    }

    public function rollback(): void
    {
        if ($this->checkpoint !== null) {
            foreach ($this->checkpoint as $property => $value) {
                $this->{$property} = $value;
            }
        }
        $this->checkpoint = null;
    }

    public function created(LegacyItem $item, string $type): void
    {
        $this->count($item, self::CREATED);
        $this->createdByType[$item->source][$type] = ($this->createdByType[$item->source][$type] ?? 0) + 1;
        $status = $item->isPublished() ? 'published' : 'draft';
        $this->createdByStatus[$status] = ($this->createdByStatus[$status] ?? 0) + 1;
        if ($item->draftReason !== null) {
            $this->draftReasons[$item->draftReason] = ($this->draftReasons[$item->draftReason] ?? 0) + 1;
            $this->details['drafts'][] = ['source' => $item->source, 'id' => $item->id, 'reason' => $item->draftReason];
        }
    }

    public function mergedIntoDraft(LegacyItem $item, string $into): void
    {
        $this->details['merged_into_draft'][] = ['source' => $item->source, 'id' => $item->id, 'into' => $into];
    }

    public function nonPdfFile(LegacyItem $item, string $extension): void
    {
        $this->details['non_pdf_files'][] = ['source' => $item->source, 'id' => $item->id, 'file' => $item->file, 'extension' => $extension];
    }

    public function coverFailure(LegacyItem $item, string $reason): void
    {
        $this->details['cover_failures'][] = ['source' => $item->source, 'id' => $item->id, 'reason' => $reason];
    }

    /** @param  array{disk: string, key: string, source: string, id: int}  $entry */
    public function orphan(array $entry): void
    {
        $this->details['orphans'][] = $entry;
    }

    public function orphanCleaned(): void
    {
        $this->orphansCleaned++;
    }

    public function cleanupFailure(string $disk, string $key): void
    {
        $this->details['cleanup_failures'][] = ['disk' => $disk, 'key' => $key];
    }

    public function fatal(string $message): void
    {
        $this->fatal = $message;
    }

    public function updated(LegacyItem $item, bool $changed): void
    {
        $this->count($item, $changed ? self::UPDATED : self::UNCHANGED);
    }

    public function merged(LegacyItem $item, string $into, string $md5): void
    {
        $this->count($item, self::MERGED);
        $this->details['merged'][] = ['source' => $item->source, 'id' => $item->id, 'into' => $into, 'md5' => $md5];
    }

    public function skipped(LegacyItem $item, string $reason): void
    {
        $this->count($item, self::SKIPPED);
        $this->details['skipped'][] = ['source' => $item->source, 'id' => $item->id, 'reason' => $reason];
    }

    public function missingFile(LegacyItem $item): void
    {
        $this->count($item, self::MISSING_FILE);
        $this->details['missing_files'][] = ['source' => $item->source, 'id' => $item->id, 'file' => $item->file];
    }

    public function error(string $source, int $id, string $message): void
    {
        if (isset($this->sources[$source])) {
            $this->sources[$source][self::ERROR]++;
        }
        $this->details['errors'][] = ['source' => $source, 'id' => $id, 'message' => $message];
    }

    public function fileFound(LegacyItem $item, LocatedFile $file): void
    {
        $this->files[$file->tolerant ? 'tolerant' : 'exact']++;
        if ($file->tolerant) {
            $this->details['tolerant_matches'][] = [
                'source' => $item->source, 'id' => $item->id, 'file' => $item->file,
                // Nom du disque, éventuellement en Latin-1 : rendu lisible (et sérialisable en JSON).
                'found' => mb_check_encoding($file->relative, 'UTF-8') ? $file->relative : mb_convert_encoding($file->relative, 'UTF-8', 'Windows-1252'),
            ];
        }
    }

    /** @param  'copied'|'generated'|'failed'|'image_missing'|'image_invalid'  $outcome */
    public function cover(string $outcome): void
    {
        $this->covers[$outcome]++;
    }

    /** @param  'created'|'existing'|'conflicts'  $outcome */
    public function redirect(LegacyPath $path, string $outcome, string $target, ?string $existingTarget = null): void
    {
        $this->redirects[$outcome]++;
        $this->redirects['by_form'][$path->form][$outcome] = ($this->redirects['by_form'][$path->form][$outcome] ?? 0) + 1;
        if ($outcome === 'conflicts') {
            $this->details['redirect_conflicts'][] = ['from' => $path->path, 'wanted' => $target, 'kept' => $existingTarget];
        }
    }

    public function redirectsDiscarded(int $count): void
    {
        $this->redirects['discarded'] += $count;
    }

    public function keywordCreated(): void
    {
        $this->keywordsCreated++;
    }

    public function systemUserCreated(): void
    {
        $this->systemUserCreated = true;
    }

    /** @param  array<string, int>  $candidates */
    public function acronymCandidates(array $candidates): void
    {
        $this->acronymCandidates = $candidates;
    }

    public function hasErrors(): bool
    {
        return $this->details['errors'] !== [] || $this->fatal !== null;
    }

    /** @return array<string, int> */
    public function totals(): array
    {
        $totals = array_fill_keys(self::OUTCOMES, 0);
        foreach ($this->sources as $counts) {
            foreach ($counts as $outcome => $count) {
                $totals[$outcome] += $count;
            }
        }

        return $totals;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        ksort($this->redirects['by_form']);

        return [
            'started_at' => $this->startedAt,
            'finished_at' => Carbon::now()->utc()->toIso8601String(),
            'dry_run' => $this->dryRun,
            'options' => $this->options,
            'totals' => $this->totals(),
            'sources' => $this->sources,
            'created_by_type' => $this->createdByType,
            'created_by_status' => $this->createdByStatus,
            'draft_reasons' => $this->draftReasons,
            'files' => $this->files,
            'covers' => $this->covers,
            'keywords_created' => $this->keywordsCreated,
            'system_user_created' => $this->systemUserCreated,
            'redirects' => $this->redirects,
            'acronym_candidates' => $this->acronymCandidates,
            'orphans_cleaned' => $this->orphansCleaned,
            'fatal' => $this->fatal,
        ] + $this->details;
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        return [
            'sources' => $this->sources, 'createdByType' => $this->createdByType, 'createdByStatus' => $this->createdByStatus,
            'draftReasons' => $this->draftReasons, 'files' => $this->files, 'covers' => $this->covers,
            'redirects' => $this->redirects, 'details' => $this->details,
        ];
    }

    private function count(LegacyItem $item, string $outcome): void
    {
        $this->sources[$item->source][$outcome]++;
    }
}
