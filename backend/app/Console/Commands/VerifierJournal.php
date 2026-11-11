<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Audit\ChaineJournal;
use Illuminate\Console\Command;

/** Lot 14d-1 (D30) : vérifie la chaîne d'empreintes du journal d'activité (le contrôle SEC_JOURNAL le fait chaque matin). */
final class VerifierJournal extends Command
{
    protected $signature = 'audit:verifier-journal {--reprendre= : Après enquête, repart du dernier rang actuel (motif obligatoire)}';

    protected $description = 'Vérifie la chaîne d\'empreintes du journal d\'activité et liste les ruptures';

    public function handle(ChaineJournal $chaine): int
    {
        if (is_string($this->option('reprendre'))) {
            $chaine->reprendre($this->option('reprendre'));
            $this->warn('Dernier sceau repris au rang actuel ; la reprise est journalisée.');
        }
        $scellees = $chaine->sceller();
        $ruptures = $chaine->ruptures();
        $this->line("Entrées scellées à l'instant : {$scellees}.");
        if ($ruptures === []) {
            $this->info('Chaîne intacte.');

            return self::SUCCESS;
        }
        $this->table(['Entrée', 'Rang', 'Rupture'], array_map(fn (array $r): array => [$r['id'] ?? '—', $r['rang'] ?? '—',
            ChaineJournal::NATURES[$r['nature']] ?? $r['nature']], $ruptures));

        return self::FAILURE;
    }
}
