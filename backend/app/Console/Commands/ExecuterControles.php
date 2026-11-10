<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Supervision\Supervision;
use Illuminate\Console\Command;

/** Lot 14a (D30) : exécute les contrôles du contrôle interne (planifié chaque jour à 05:30). */
final class ExecuterControles extends Command
{
    protected $signature = 'supervision:executer {--controle=* : Codes des contrôles à exécuter (tous par défaut)}';

    protected $description = 'Exécute les contrôles du contrôle interne et met à jour leurs anomalies.';

    public function handle(Supervision $supervision): int
    {
        $codes = $this->option('controle');
        $bilan = $supervision->executer($codes === [] ? null : $codes);
        foreach ($bilan as $code => $b) {
            $this->line(sprintf('%-20s %3d trouvé(s), %3d nouveau(x), %3d corrigé(s)%s', $code, $b['trouves'], $b['nouveaux'], $b['corriges'],
                $b['erreur'] !== null ? ' — ERREUR : '.$b['erreur'] : ''));
        }

        return collect($bilan)->contains(fn (array $b): bool => $b['erreur'] !== null) ? self::FAILURE : self::SUCCESS;
    }
}
