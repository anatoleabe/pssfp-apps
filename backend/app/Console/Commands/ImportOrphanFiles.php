<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Library\Import\OrphanFileImporter;
use App\Services\Library\Import\OrphanImportReport;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\Console\Helper\ProgressBar;
use Throwable;

/** Publie les fichiers de l'ancien serveur jamais catalogués dans Joomla (plan 4, task 2). */
final class ImportOrphanFiles extends Command
{
    protected $signature = 'biblio:import-orphans
        {export : Export JSON produit par biblio:extract-joomla}
        {--files= : Dossier des fichiers copiés du serveur (Documentations/, livres/)}
        {--dry-run : Simulation : aucune écriture en base, sur MinIO ni de redirection, même rapport}
        {--limit= : Nombre maximal de fiches à créer}';

    protected $description = 'Publie les PDF de l\'ancien serveur qu\'aucune fiche Joomla ne référence (idempotent).';

    public function handle(OrphanFileImporter $importer): int
    {
        // Même verrou que biblio:import : les deux se disputeraient slugs, fichiers et redirections.
        $lock = fopen(storage_path('logs/biblio-import.lock'), 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            $this->error('Un import de la bibliothèque est déjà en cours (verrou storage/logs/biblio-import.lock) : attendez sa fin.');

            return self::FAILURE;
        }

        try {
            return $this->runImport($importer);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function runImport(OrphanFileImporter $importer): int
    {
        // Arguments vérifiés avant le lancement : une erreur pendant le lancement, quelle qu'elle
        // soit, passe par le rapport partiel ci-dessous.
        try {
            $limit = $this->option('limit');
            if ($limit !== null && ! ctype_digit((string) $limit)) {
                throw new InvalidArgumentException('--limit attend un entier strictement positif.');
            }
            $limit = $limit === null ? null : (int) $limit;
            $export = $this->readExport((string) $this->argument('export'));
            $files = (string) $this->option('files');
            OrphanFileImporter::validate($export, $files, $limit);
        } catch (InvalidArgumentException|JsonException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            $bar = null;
            $report = $importer->import(
                $export,
                $files,
                (bool) $this->option('dry-run'),
                $limit,
                storage_path('logs'),
                function (int $done, int $total) use (&$bar): void {
                    $bar ??= $this->output->createProgressBar($total);
                    $bar->setProgress($done);
                },
            );
            $bar instanceof ProgressBar && $bar->finish();
            $this->newLine();
        } catch (Throwable $e) {
            // Incident imprévu (préalable, base, MinIO…) : rapport partiel écrit malgré tout.
            $this->newLine();
            $this->error('Import interrompu : '.$e->getMessage());
            $report = $importer->partialReport();
            if ($report !== null) {
                $report->fatal($e->getMessage());
                $this->summary($report, $this->writeReport($report));
            }

            return self::FAILURE;
        }

        $this->summary($report, $this->writeReport($report));

        return $report->hasErrors() ? self::FAILURE : self::SUCCESS;
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

    /** storage/logs/biblio-orphelins-{date}[-dry-run].json, lisible du seul propriétaire (chemins réels). */
    private function writeReport(OrphanImportReport $report): ?string
    {
        $path = storage_path(sprintf('logs/biblio-orphelins-%s%s.json', Carbon::now()->format('YmdHis'), $report->dryRun ? '-dry-run' : ''));
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

    private function summary(OrphanImportReport $report, ?string $path): void
    {
        $data = $report->toArray();
        $t = $data['totals'];
        $this->info($report->dryRun ? 'Simulation (dry-run) : aucune écriture.' : 'Import des fichiers jamais catalogués terminé.');
        $this->table(
            ['Créés', 'Déjà au catalogue', 'Variantes', 'Doublons', 'Exclus', 'Sans règle', 'Non-PDF', 'Erreurs'],
            [[$t['created'], $t['known'], $t['variant'], $t['duplicate'], $t['excluded'], $t['unmapped'], $t['non_pdf'], $t['errors']]],
        );
        $this->line(sprintf(
            'Fichiers référencés par l\'export (ignorés) : %d · Redirections : %d créées, %d existantes, %d en conflit (conservées), %d écartées · Couvertures : %d générées, %d en échec',
            $data['referenced'], $data['redirects']['created'], $data['redirects']['existing'], $data['redirects']['conflicts'],
            $data['redirects']['discarded'], $data['covers']['generated'], $data['covers']['failed'],
        ));
        if ($data['pending_objects'] !== []) {
            $this->warn(sprintf('%d objet(s) MinIO en attente d\'un import interrompu, qu\'aucun document ne référence — voir « pending_objects » dans le rapport ; biblio:import --clean-orphans pour les supprimer.', count($data['pending_objects'])));
        }
        if ($data['cover_failures'] !== []) {
            $this->warn(sprintf('%d couverture(s) en échec — voir « cover_failures » dans le rapport.', count($data['cover_failures'])));
        }
        if ($data['not_processed'] > 0) {
            $this->warn("Limite atteinte (--limit) : {$data['not_processed']} fichier(s) non traité(s).");
        }
        $errors = array_values(array_filter($data['files'], fn (array $file): bool => $file['outcome'] === OrphanImportReport::ERROR));
        foreach (array_slice($errors, 0, 10) as $error) {
            $this->error("{$error['path']} : {$error['message']}");
        }
        if ($path !== null) {
            $this->line("Rapport détaillé : {$path}");
        }
    }
}
