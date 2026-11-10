<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Messagerie\CopiesExternes;
use Illuminate\Console\Command;

/** Lot 14b-1 (D31) : envoie les copies externes (courriel, SMS) des messages internes. */
final class EnvoyerCopiesMessages extends Command
{
    protected $signature = 'messagerie:envoyer {--lot=200 : nombre de copies traitées au plus}';

    protected $description = 'Envoie les copies externes (courriel, SMS) des messages de la messagerie interne';

    public function handle(CopiesExternes $copies): int
    {
        $bilan = $copies->traiter(max(1, (int) $this->option('lot')));
        $this->info("Copies envoyées : {$bilan['envoyes']} ; à retenter : {$bilan['en_attente']} ; abandonnées : {$bilan['echecs']}.");

        return self::SUCCESS;
    }
}
