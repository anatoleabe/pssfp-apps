<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Acma\ErreurAcma;
use App\Services\Acma\InventaireAcma;
use Illuminate\Console\Command;
use Throwable;

/** Lot 15a (spec du lot 15, §3) : comptes de l'export chargé dans le transit, sans aucune donnée nominative. */
final class AcmaInventaire extends Command
{
    protected $signature = 'acma:inventaire';

    protected $description = 'Inventaire de l\'export ACMA chargé dans le transit (comptes seulement)';

    public function handle(InventaireAcma $inventaire): int
    {
        try {
            $sections = $inventaire->calculer();
        } catch (Throwable $e) {
            // Aucune donnée à la console : message de la reprise, ou SQLSTATE, table et contrainte seulement.
            $this->error(ErreurAcma::sansDonnees($e, 'de l\'inventaire')->getMessage());

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
