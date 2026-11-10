<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Supervision\Relances;
use App\Services\Supervision\Supervision;
use Illuminate\Console\Command;

/** Lots 14a et 14b-2 (D30) : exécute les contrôles du contrôle interne puis envoie les relances dues (chaque jour à 05:30). */
final class ExecuterControles extends Command
{
    protected $signature = 'supervision:executer {--controle=* : Codes des contrôles à exécuter (tous par défaut)} {--sans-relances : N\'envoie pas les relances}';

    protected $description = 'Exécute les contrôles du contrôle interne et met à jour leurs anomalies.';

    public function handle(Supervision $supervision, Relances $relances): int
    {
        $codes = $this->option('controle');
        $bilan = $supervision->executer($codes === [] ? null : $codes);
        foreach ($bilan as $code => $b) {
            $this->line(sprintf('%-20s %3d trouvé(s), %3d nouveau(x), %3d corrigé(s)%s', $code, $b['trouves'], $b['nouveaux'], $b['corriges'],
                $b['erreur'] !== null ? ' — ERREUR : '.$b['erreur'] : ''));
        }

        if (! $this->option('sans-relances')) {
            $r = $relances->generer();
            $this->line(sprintf('Relances : %d envoyée(s) en %d message(s), %d sans destinataire.', $r['relances'], $r['messages'], $r['sans_destinataire']));
        }

        return collect($bilan)->contains(fn (array $b): bool => $b['erreur'] !== null) ? self::FAILURE : self::SUCCESS;
    }
}
