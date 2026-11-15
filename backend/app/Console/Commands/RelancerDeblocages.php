<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Scolarite\RelancesDeblocages;
use Illuminate\Console\Command;

/** Accès anticipé (spec module 4, lot A1 §5) : relance les déblocages échus, chaque jour à 08:00 heure de Yaoundé. */
final class RelancerDeblocages extends Command
{
    protected $signature = 'scolarite:relancer-deblocages';

    protected $description = 'Relance par SMS et e-mail les auditeurs dont l\'accès anticipé est échu, et régularise ceux qui ont soldé.';

    public function handle(RelancesDeblocages $relances): int
    {
        $b = $relances->relancer();
        $this->line(sprintf('%d relance(s), %d régularisé(s), %d écarté(s), %d échec(s).', $b['relances'], $b['regularises'], $b['ignores'], $b['echecs']));

        return self::SUCCESS;
    }
}
