<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Library\Import\ImportOptions;
use App\Services\Library\Import\ImportReport;
use App\Services\Library\Import\ImportSources;
use App\Services\Library\Import\LegacyImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\Console\Helper\ProgressBar;

/** Importe l'ancienne bibliothèque Joomla dans la bibliothèque V1 (plan 3, tasks 4 et 5). */
final class ImportJoomlaLibrary extends Command
{
    protected $signature = 'biblio:import
        {export : Export JSON produit par biblio:extract-joomla}
        {--files= : Dossier des fichiers copiés du FTP (Documentations/, livres/, images/)}
        {--dry-run : Simulation : aucune écriture en base ni sur MinIO, même rapport}
        {--limit= : Nombre maximal d\'éléments à traiter}
        {--only= : Une seule source : docman ou alexandria}';

    protected $description = 'Importe documents, livres, fichiers, couvertures et anciennes URL de la bibliothèque Joomla (idempotent).';

    public function handle(LegacyImporter $importer): int
    {
        try {
            $options = $this->importOptions();
            $export = $this->readExport((string) $this->argument('export'));
            $files = (string) $this->option('files');
            if ($files === '' || ! is_dir($files)) {
                throw new InvalidArgumentException("Dossier des fichiers introuvable (--files) : {$files}");
            }

            $bar = null;
            $report = $importer->import($export, ImportSources::fromDirectory($files), $options, function (int $done, int $total) use (&$bar): void {
                $bar ??= $this->output->createProgressBar($total);
                $bar->setProgress($done);
            });
            $bar instanceof ProgressBar && $bar->finish();
            $this->newLine();
        } catch (InvalidArgumentException|JsonException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $path = $this->writeReport($report);
        $this->summary($report, $path);

        return $report->hasErrors() ? self::FAILURE : self::SUCCESS;
    }

    private function importOptions(): ImportOptions
    {
        $limit = $this->option('limit');
        if ($limit !== null && ! ctype_digit((string) $limit)) {
            throw new InvalidArgumentException('--limit attend un entier strictement positif.');
        }
        $only = $this->option('only');

        return new ImportOptions(
            dryRun: (bool) $this->option('dry-run'),
            limit: $limit === null ? null : (int) $limit,
            only: $only === null ? null : (string) $only,
        );
    }

    /** @return array<string, mixed> */
    private function readExport(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("Export introuvable ou illisible : {$path}");
        }

        $export = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($export)) {
            throw new InvalidArgumentException('Export Joomla invalide : objet JSON attendu.');
        }

        return $export;
    }

    /** storage/logs/biblio-import-{date}[-dry-run].json, lisible du seul propriétaire (titres et chemins réels). */
    private function writeReport(ImportReport $report): ?string
    {
        $path = storage_path(sprintf('logs/biblio-import-%s%s.json', Carbon::now()->format('Ymd-His'), $report->dryRun ? '-dry-run' : ''));
        $json = json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        $umask = umask(0077);
        try {
            $written = $json !== false && file_put_contents($path, $json."\n", LOCK_EX) !== false && chmod($path, 0600);
        } finally {
            umask($umask);
        }

        if (! $written) {
            $this->warn("Rapport non écrit : {$path}");

            return null;
        }

        return $path;
    }

    private function summary(ImportReport $report, ?string $path): void
    {
        $data = $report->toArray();
        $this->info($report->dryRun ? 'Simulation (dry-run) : aucune écriture.' : 'Import terminé.');
        $this->table(
            ['Source', 'Créés', 'Mis à jour', 'Inchangés', 'Fusionnés (MD5)', 'Ignorés', 'Fichiers introuvables', 'Erreurs'],
            array_map(
                fn (string $source, array $c): array => [$source, $c['created'], $c['updated'], $c['unchanged'], $c['merged'], $c['skipped'], $c['missing_files'], $c['errors']],
                array_keys($data['sources']),
                $data['sources'],
            ),
        );
        $redirects = $data['redirects'];
        $this->line(sprintf(
            'Redirections : %d créées, %d existantes, %d en conflit (conservées), %d écartées · Couvertures : %d copiées, %d générées · Mots-clés créés : %d',
            $redirects['created'], $redirects['existing'], $redirects['conflicts'], $redirects['discarded'],
            $data['covers']['copied'], $data['covers']['generated'], $data['keywords_created'],
        ));
        foreach (array_slice($data['errors'], 0, 10) as $error) {
            $this->error("{$error['source']} n° {$error['id']} : {$error['message']}");
        }
        if ($path !== null) {
            $this->line("Rapport détaillé : {$path}");
        }
    }
}
