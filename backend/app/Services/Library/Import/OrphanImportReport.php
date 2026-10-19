<?php

declare(strict_types=1);

namespace App\Services\Library\Import;

use Illuminate\Support\Carbon;

/**
 * Rapport de biblio:import-orphans (console + storage/logs/biblio-orphelins-{date}.json) : une
 * issue par fichier orphelin, chemin relatif en UTF-8. Identique en dry-run (« créés » se lit alors
 * « seraient créés »).
 */
final class OrphanImportReport implements RedirectRecorder
{
    public const CREATED = 'created';

    public const KNOWN = 'known';

    public const VARIANT = 'variant';

    public const DUPLICATE = 'duplicate';

    public const EXCLUDED = 'excluded';

    public const UNMAPPED = 'unmapped';

    public const NON_PDF = 'non_pdf';

    public const ERROR = 'error';

    /** Issue → clé des totaux (« errors » comme dans le rapport de biblio:import). */
    private const TOTALS = [
        self::CREATED => 'created', self::KNOWN => 'known', self::VARIANT => 'variant', self::DUPLICATE => 'duplicate',
        self::EXCLUDED => 'excluded', self::UNMAPPED => 'unmapped', self::NON_PDF => 'non_pdf', self::ERROR => 'errors',
    ];

    /** @var array<string, int> */
    private array $totals;

    /** @var list<array<string, mixed>> */
    private array $files = [];

    /** @var array<string, int> */
    private array $createdByType = [];

    /** @var array<string, int> */
    private array $covers = ['generated' => 0, 'failed' => 0];

    /** @var array{created: int, existing: int, conflicts: int, discarded: int} */
    private array $redirects = ['created' => 0, 'existing' => 0, 'conflicts' => 0, 'discarded' => 0];

    /** @var list<array{from: string, wanted: string, kept: ?string}> */
    private array $redirectConflicts = [];

    /** @var list<array{disk: string, key: string}> */
    private array $cleanupFailures = [];

    private int $referenced = 0;

    private int $notProcessed = 0;

    private ?string $fatal = null;

    /** @var array<string, mixed>|null état avant le fichier en cours */
    private ?array $checkpoint = null;

    private readonly string $startedAt;

    /** @param  array<string, mixed>  $options */
    public function __construct(public readonly bool $dryRun, private readonly array $options = [])
    {
        $this->startedAt = Carbon::now()->utc()->toIso8601String();
        $this->totals = array_fill_keys(array_values(self::TOTALS), 0);
    }

    /** Début d'un fichier : ses compteurs seront annulés avec lui s'il échoue. */
    public function begin(): void
    {
        $this->checkpoint = [
            'totals' => $this->totals, 'files' => $this->files, 'createdByType' => $this->createdByType,
            'covers' => $this->covers, 'redirects' => $this->redirects, 'redirectConflicts' => $this->redirectConflicts,
        ];
    }

    public function rollback(): void
    {
        foreach ($this->checkpoint ?? [] as $property => $value) {
            $this->{$property} = $value;
        }
        $this->checkpoint = null;
    }

    /** @param  array<string, mixed>  $details */
    public function outcome(string $path, string $outcome, array $details = []): void
    {
        $this->totals[self::TOTALS[$outcome]]++;
        $this->files[] = ['path' => $path, 'outcome' => $outcome] + $details;
    }

    public function created(string $path, string $type, string $slug): void
    {
        $this->outcome($path, self::CREATED, ['type' => $type, 'slug' => $slug]);
        $this->createdByType[$type] = ($this->createdByType[$type] ?? 0) + 1;
    }

    public function error(string $path, string $message): void
    {
        $this->outcome($path, self::ERROR, ['message' => $message]);
    }

    /** @param  'generated'|'failed'  $outcome */
    public function cover(string $outcome): void
    {
        $this->covers[$outcome]++;
    }

    public function redirect(LegacyPath $path, string $outcome, string $target, ?string $existingTarget = null): void
    {
        $this->redirects[$outcome]++;
        if ($outcome === 'conflicts') {
            $this->redirectConflicts[] = ['from' => $path->path, 'wanted' => $target, 'kept' => $existingTarget];
        }
    }

    /** Ancienne URL trop longue pour être enregistrée. */
    public function redirectDiscarded(): void
    {
        $this->redirects['discarded']++;
    }

    public function referenced(): void
    {
        $this->referenced++;
    }

    public function notProcessed(int $count): void
    {
        $this->notProcessed = $count;
    }

    public function cleanupFailure(string $disk, string $key): void
    {
        $this->cleanupFailures[] = ['disk' => $disk, 'key' => $key];
    }

    public function fatal(string $message): void
    {
        $this->fatal = $message;
    }

    public function hasErrors(): bool
    {
        return $this->totals['errors'] > 0 || $this->fatal !== null;
    }

    /** @return array<string, int> */
    public function totals(): array
    {
        return $this->totals;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        ksort($this->createdByType);

        return [
            'started_at' => $this->startedAt,
            'finished_at' => Carbon::now()->utc()->toIso8601String(),
            'dry_run' => $this->dryRun,
            'options' => $this->options,
            'totals' => $this->totals,
            'referenced' => $this->referenced,
            'not_processed' => $this->notProcessed,
            'created_by_type' => $this->createdByType,
            'covers' => $this->covers,
            'redirects' => $this->redirects,
            'redirect_conflicts' => $this->redirectConflicts,
            'cleanup_failures' => $this->cleanupFailures,
            'fatal' => $this->fatal,
            'files' => $this->files,
        ];
    }
}
