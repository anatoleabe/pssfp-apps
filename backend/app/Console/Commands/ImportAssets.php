<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AssetImportService;
use Illuminate\Console\Command;

/**
 * Commande artisan `pssfp:import-assets` (cf. spec sprint S5 PR V).
 *
 * Scanne le dossier source (par défaut `assets-source/` à la racine du repo),
 * uploade chaque fichier vers MinIO et crée la fiche dans la table `assets`.
 */
class ImportAssets extends Command
{
    /** @var string */
    protected $signature = 'pssfp:import-assets
        {--source= : Dossier source (défaut: <repo>/assets-source)}
        {--dry-run : Liste les fichiers sans uploader}';

    /** @var string */
    protected $description = 'Importe les assets (logos, photos, documents) depuis assets-source/ vers MinIO + table assets.';

    public function handle(): int
    {
        $source = $this->option('source') ?: $this->defaultSource();

        if (! is_dir($source)) {
            $this->error("Dossier source introuvable : {$source}");

            return self::FAILURE;
        }

        $this->info("Import depuis : {$source}");

        if ($this->option('dry-run')) {
            $this->warn('Mode dry-run : aucun upload ni écriture en base.');
            $this->dryRun($source);

            return self::SUCCESS;
        }

        $service = new AssetImportService($source);

        $this->output->progressStart(0);
        $report = $service->run(function (string $name, string $status): void {
            $emoji = match ($status) {
                'imported' => '✅',
                'skipped' => '⏭️ ',
                'error' => '❌',
                default => '·',
            };
            $this->line(" {$emoji} {$name}");
        });
        $this->output->progressFinish();

        $this->newLine();
        $this->info('--- Rapport ---');
        $this->line('Importés  : '.$report['imported']);
        $this->line('Ignorés   : '.$report['skipped']);
        $this->line('Erreurs   : '.$report['errors']);

        return $report['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function defaultSource(): string
    {
        return base_path('../assets-source');
    }

    private function dryRun(string $source): void
    {
        $service = new AssetImportService($source);
        $reflection = new \ReflectionMethod($service, 'collectFiles');
        $reflection->setAccessible(true);
        $files = $reflection->invoke($service);

        $count = 0;
        foreach ($files as $file) {
            $count++;
            $this->line(' · '.$file->getRelativePathname());
        }

        $this->newLine();
        $this->info("Total fichiers détectés : {$count}");
    }
}
