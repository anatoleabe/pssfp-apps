<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Audit\ChaineJournal;
use Illuminate\Console\Command;

/** Lot 14d-1 (D30) : scelle les entrées du journal d'activité (chaque minute). */
final class ScellerJournal extends Command
{
    protected $signature = 'audit:sceller-journal';

    protected $description = 'Scelle les nouvelles entrées du journal d\'activité dans la chaîne d\'empreintes';

    public function handle(ChaineJournal $chaine): int
    {
        $n = $chaine->sceller();
        if ($n > 0 || $this->getOutput()->isVerbose()) {
            $this->info("Entrées scellées : {$n}.");
        }

        return self::SUCCESS;
    }
}
