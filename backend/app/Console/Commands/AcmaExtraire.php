<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\CommandeAcma;
use App\Services\Acma\ExtractionAcma;
use App\Services\Acma\SourceAcma;
use App\Services\Acma\SourceAcmaMysql;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Lot 15a (spec du lot 15, §10.1, étape 2) : sur le poste de l'USI, CSV des tables utiles d'ACMA, manifeste, puis
 * archive chiffrée. Le dossier en clair est supprimé. La console n'affiche que des comptes.
 */
final class AcmaExtraire extends Command
{
    use CommandeAcma;

    protected $signature = 'acma:extraire
        {--sortie= : Dossier privé où écrire l\'archive chiffrée}
        {--export= : Fichier d\'export d\'ACMA chargé dans le MariaDB jetable (pour son empreinte)}
        {--date-export= : Date de l\'export (AAAA-MM-JJ)}';

    protected $description = 'Extrait les tables utiles d\'ACMA en CSV, avec manifeste, dans une archive chiffrée (poste de l\'USI)';

    public function handle(ExtractionAcma $extraction): int
    {
        if (config('database.connections.acma_source.host') === null && ! app()->bound(SourceAcma::class)) {
            $this->error('Connexion acma_source non définie (ACMA_DB_HOST) : cette commande ne s\'exécute que sur le poste de l\'USI.');

            return self::FAILURE;
        }
        $sortie = (string) $this->option('sortie');
        if ($sortie === '') {
            $this->error('--sortie est obligatoire.');

            return self::FAILURE;
        }
        $export = (string) $this->option('export');
        if ($export !== '' && ! is_readable($export)) {
            $this->error('Fichier d\'export illisible.');

            return self::FAILURE;
        }
        $phrase = $this->phraseAcma(true);
        if ($phrase === null) {
            return self::FAILURE;
        }
        $source = app()->bound(SourceAcma::class) ? app(SourceAcma::class) : new SourceAcmaMysql;

        try {
            $resultat = $extraction->extraire($source, $sortie, [
                'fichier' => $export !== '' ? basename($export) : null,
                'sha256' => $export !== '' ? (string) hash_file('sha256', $export) : null,
                'date' => $this->option('date-export') ?: null,
            ], $phrase);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $rows = [];
        foreach ($resultat['manifeste']['tables'] as $table => $t) {
            $rows[] = [$table, count($t['colonnes']), $t['lignes'], $t['utf8_corriges']];
        }
        $this->table(['Table', 'Colonnes', 'Lignes', 'Valeurs UTF-8 corrigées'], $rows);
        $this->info('Archive chiffrée : '.$resultat['archive']);
        $this->line('Tables non extraites : '.count($resultat['manifeste']['exclusions']['tables']).'. Le dossier en clair a été supprimé.');

        return self::SUCCESS;
    }
}
