<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Lmd\ImportMaquettes;
use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;

/**
 * Import des maquettes d'une année (spec module 4, lot 4). Essai à blanc par
 * défaut : rien n'est écrit sans --appliquer. Les maquettes arrivent en brouillon.
 */
final class ImporterMaquettes extends Command
{
    protected $signature = 'lmd:importer-maquettes
        {fichier=resources/lmd/maquettes-2026-2027.json : Fichier JSON des maquettes}
        {--appliquer : Écrire en base (sinon essai à blanc)}';

    protected $description = 'Importe les maquettes pédagogiques d\'une année, en brouillon';

    public function handle(ImportMaquettes $import): int
    {
        $chemin = (string) $this->argument('fichier');
        $chemin = is_readable($chemin) ? $chemin : base_path($chemin);
        if (! is_readable($chemin)) {
            $this->error("Fichier illisible : {$this->argument('fichier')}");

            return self::FAILURE;
        }
        $appliquer = (bool) $this->option('appliquer');

        try {
            $document = json_decode((string) file_get_contents($chemin), true, 32, JSON_THROW_ON_ERROR);
            $b = $import->importer((array) $document, $appliquer);
        } catch (InvalidArgumentException|JsonException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(($appliquer ? 'Import' : 'Essai à blanc').' des maquettes '.$b['annee'].' :');
        $this->line('  créées : '.($b['creees'] === [] ? 'aucune' : implode(', ', $b['creees'])));
        $this->line('  brouillons remplacés : '.($b['remplacees'] === [] ? 'aucun' : implode(', ', $b['remplacees'])));
        foreach ($b['ignorees'] as $code => $motif) {
            $this->line("  laissée telle quelle : {$code} ({$motif})");
        }
        foreach ($b['ecarts'] as $ecart) {
            $this->warn('  '.$ecart);
        }

        return self::SUCCESS;
    }
}
