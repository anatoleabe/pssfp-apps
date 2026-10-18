<?php

declare(strict_types=1);

namespace App\Services\Library\Import;

use App\Models\LibraryRedirect;
use RuntimeException;

/**
 * Enregistre les redirections des anciennes URL (firstOrCreate : idempotent). Une ancienne URL
 * déjà redirigée ailleurs (saisie à la main, autre document) est conservée et signalée.
 * En dry-run, rien n'est écrit mais le décompte est le même (URL planifiées gardées en mémoire).
 */
final class RedirectWriter
{
    /** @var array<string, string> ancienne URL → cible, pour tout le lancement */
    private array $planned = [];

    /** @var list<string> URL planifiées par l'élément en cours (annulées s'il échoue) */
    private array $pending = [];

    public function __construct(private readonly ImportReport $report, private readonly bool $dryRun) {}

    /**
     * @param  list<LegacyPath>  $paths
     * @return array<string, bool> ancienne URL → créée par cet appel
     */
    public function ensure(array $paths, string $target): array
    {
        if (! LibraryRedirect::isSafeTarget($target)) {
            throw new RuntimeException("Cible de redirection refusée : {$target}");
        }
        if ($paths === []) {
            return [];
        }

        $known = LibraryRedirect::query()
            ->whereIn('from_path', array_map(fn (LegacyPath $p): string => $p->path, $paths))
            ->pluck('to_path', 'from_path')
            ->all();

        $created = [];
        foreach ($paths as $path) {
            $kept = $this->planned[$path->path] ?? $known[$path->path] ?? null;
            if ($kept === null && ! $this->dryRun) {
                $redirect = LibraryRedirect::query()->firstOrCreate(
                    ['from_path' => $path->path],
                    ['to_path' => $target, 'status_code' => 301],
                );
                $kept = $redirect->wasRecentlyCreated ? null : $redirect->to_path;
            }

            $created[$path->path] = $kept === null;
            if ($kept === null) {
                $this->planned[$path->path] = $target;
                $this->pending[] = $path->path;
                $this->report->redirect($path, 'created', $target);
            } else {
                $this->report->redirect($path, $kept === $target ? 'existing' : 'conflicts', $target, $kept);
            }
        }

        return $created;
    }

    public function begin(): void
    {
        $this->pending = [];
    }

    /** L'élément a échoué (transaction annulée) : ses URL ne sont plus planifiées. */
    public function rollback(): void
    {
        foreach ($this->pending as $path) {
            unset($this->planned[$path]);
        }
        $this->pending = [];
    }
}
