<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\CommandeAcma;
use App\Services\Acma\Reprise\GelArchive;
use Illuminate\Console\Command;
use RuntimeException;

/** Lot 15c (spec du lot 15, §7.3) : gel définitif de l'archive ACMA, après confirmation. */
final class AcmaGeler extends Command
{
    use CommandeAcma;

    protected $signature = 'acma:geler {--auteur= : E-mail du compte super_admin ou admin qui gèle l\'archive}';

    protected $description = 'Gèle définitivement l\'archive ACMA (plus aucune reprise possible)';

    public function handle(GelArchive $gel): int
    {
        $auteur = $this->auteurAcma();
        if ($auteur === null) {
            return self::FAILURE;
        }
        AcmaReprendre::memoire();
        try {
            $reprise = $gel->verifier();
            if (! $this->confirm("Geler définitivement l'archive (reprise {$reprise->uuid}) ? Aucune reprise ne sera plus possible.")) {
                $this->warn('Gel abandonné.');

                return self::FAILURE;
            }
            $uuid = $gel->geler($auteur);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("Archive gelée (reprise {$uuid}).");

        return self::SUCCESS;
    }
}
