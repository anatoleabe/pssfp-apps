<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\CommandeAcma;
use App\Services\Acma\Reprise\GelArchive;
use Illuminate\Console\Command;
use RuntimeException;

/** Lot 15c (spec du lot 15, §10.6) : suppression du schéma de transit, après le gel et après confirmation. */
final class AcmaPurgerTransit extends Command
{
    use CommandeAcma;

    protected $signature = 'acma:purger-transit {--auteur= : E-mail du compte super_admin ou admin qui purge le transit}';

    protected $description = 'Supprime le schéma de transit ACMA (seulement après le gel de l\'archive)';

    public function handle(GelArchive $gel): int
    {
        $auteur = $this->auteurAcma();
        if ($auteur === null) {
            return self::FAILURE;
        }
        try {
            $gel->verifierPurge();
            if (! $this->confirm('Supprimer le schéma de transit ACMA ? L\'archive gelée reste en place.')) {
                $this->warn('Purge abandonnée.');

                return self::FAILURE;
            }
            $gel->purgerTransit($auteur);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info('Transit ACMA purgé.');

        return self::SUCCESS;
    }
}
