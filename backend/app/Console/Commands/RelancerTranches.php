<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Scolarite\RelancesTranches;
use Illuminate\Console\Command;

/** Tranches échues (spec lot 23) : relance des auditeurs en retard, chaque jour à 08:30 heure de Yaoundé, si le réglage est allumé. */
final class RelancerTranches extends Command
{
    protected $signature = 'scolarite:relancer-tranches';

    protected $description = 'Relance par SMS et e-mail les auditeurs dont une tranche est échue, si la relance est activée dans Paramètres.';

    public function handle(RelancesTranches $relances): int
    {
        $b = $relances->relancer();
        $this->line($b['actif']
            ? sprintf('%d relance(s), %d écarté(s), %d échec(s), %d reporté(s) à demain.', $b['relances'], $b['ignores'], $b['echecs'], $b['reportes'])
            : 'Relance des tranches désactivée (Paramètres) : rien n\'est parti.');

        return self::SUCCESS;
    }
}
