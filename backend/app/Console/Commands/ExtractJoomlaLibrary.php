<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Library\Import\JoomlaLibraryExtractor;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

/** Extrait du dump MySQL de l'ancienne bibliothèque Joomla un JSON pour biblio:import (plan 3, task 2). */
final class ExtractJoomlaLibrary extends Command
{
    protected $signature = 'biblio:extract-joomla
        {dump : Chemin du dump SQL de l\'ancienne bibliothèque}
        {sortie : Fichier JSON à produire (hors dépôt : données réelles)}
        {--prefix=oe179_ : Préfixe des tables Joomla}';

    protected $description = 'Extrait DOCman, Alexandria et les menus du dump Joomla en JSON (lecture en flux, sans MySQL).';

    public function handle(JoomlaLibraryExtractor $extractor): int
    {
        $dump = (string) $this->argument('dump');
        $output = (string) $this->argument('sortie');
        $prefix = (string) $this->option('prefix');

        if (preg_match('/^[A-Za-z0-9_]*$/', $prefix) !== 1) {
            $this->error("Préfixe de table invalide : {$prefix}");

            return self::FAILURE;
        }
        if (! is_file($dump) || ! is_readable($dump)) {
            $this->error("Dump introuvable ou illisible : {$dump}");

            return self::FAILURE;
        }

        try {
            $export = ['generated_at' => now()->utc()->toIso8601String()] + $extractor->extract($dump, $prefix);
            $json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (RuntimeException|JsonException $e) {
            $this->error('Extraction impossible : '.$e->getMessage());

            return self::FAILURE;
        }

        // Données réelles : fichier créé directement en 0600 (umask), puis chmod si il existait déjà.
        $umask = umask(0077);
        try {
            $written = file_put_contents($output, $json."\n", LOCK_EX) !== false && chmod($output, 0600);
        } finally {
            umask($umask);
        }

        if (! $written) {
            $this->error("Écriture impossible : {$output}");

            return self::FAILURE;
        }

        $this->info(sprintf(
            'DOCman : %d catégories, %d documents · Alexandria : %d catégories, %d livres · %d menus → %s',
            count($export['docman']['categories']),
            count($export['docman']['documents']),
            count($export['alexandria']['categories']),
            count($export['alexandria']['books']),
            count($export['menus']),
            $output,
        ));

        return self::SUCCESS;
    }
}
