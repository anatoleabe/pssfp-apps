<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Lmd\ImportCalendrier;
use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;

/**
 * Reprise d'un calendrier académique déjà signé (spec module 4, lot 12a). Essai à blanc par
 * défaut : rien n'est écrit sans --appliquer.
 */
final class ImporterCalendrier extends Command
{
    protected $signature = 'lmd:importer-calendrier
        {fichier=resources/lmd/calendrier-2026-2027.json : Fichier JSON du calendrier}
        {--appliquer : Écrire en base (sinon essai à blanc)}';

    protected $description = 'Reprend un calendrier académique signé (événements, phases, jours non ouvrés, dépendances, actes)';

    public function handle(ImportCalendrier $import): int
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

        $this->info(($appliquer ? 'Reprise' : 'Essai à blanc').' du calendrier '.$b['annee'].' : '.$b['evenements'].' événements, '
            .$b['phases'].' phases, '.$b['jours'].' jours non ouvrés, '.$b['dependances'].' dépendances, '.$b['actes'].' actes à produire.');
        $this->line(count($b['anomalies']).' anomalie(s) bloquante(s) à arbitrer par la scolarité :');
        foreach ($b['anomalies'] as $a) {
            $this->warn('  '.$a);
        }

        if ($b['alertes'] !== []) {
            $this->line(count($b['alertes']).' acte(s) dû(s) pendant une interruption :');
            foreach ($b['alertes'] as $a) {
                $this->warn('  '.$a);
            }
        }

        return self::SUCCESS;
    }
}
