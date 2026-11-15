<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Acma\InventaireAcma;
use Illuminate\Console\Command;
use RuntimeException;

/** Lot 15a (spec du lot 15, §3) : comptes de l'export chargé dans le transit, sans aucune donnée nominative. */
final class AcmaInventaire extends Command
{
    protected $signature = 'acma:inventaire';

    protected $description = 'Inventaire de l\'export ACMA chargé dans le transit (comptes seulement)';

    public function handle(InventaireAcma $inventaire): int
    {
        try {
            $sections = $inventaire->calculer();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        foreach ($sections as $titre => $lignes) {
            $this->newLine();
            $this->info($titre);
            if (InventaireAcma::tabulaire($titre)) {
                $entete = array_shift($lignes);
                $this->table($entete, $lignes);
            } else {
                $this->table(['Indicateur', 'Valeur'], $lignes);
            }
        }

        return self::SUCCESS;
    }
}
