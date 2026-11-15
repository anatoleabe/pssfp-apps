<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Scolarite\RelancesConfirmations;
use Illuminate\Console\Command;

/** Versements non confirmés depuis 7 jours (décision du 15/11/2026) : relance de l'auditeur, chaque jour à 08:00 heure de Yaoundé. */
final class RelancerConfirmations extends Command
{
    protected $signature = 'scolarite:relancer-confirmations';

    protected $description = 'Relance par SMS et e-mail les auditeurs dont un versement attend sa confirmation par la scolarité depuis 7 jours.';

    public function handle(RelancesConfirmations $relances): int
    {
        $b = $relances->relancer();
        $this->line(sprintf('%d relance(s), %d écarté(s), %d échec(s).', $b['relances'], $b['ignores'], $b['echecs']));

        return self::SUCCESS;
    }
}
