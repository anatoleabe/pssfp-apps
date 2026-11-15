<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\CommandeAcma;
use App\Services\Acma\ChargementTransit;
use App\Services\Acma\ErreurAcma;
use Illuminate\Console\Command;
use Throwable;

/**
 * Lot 15a (spec du lot 15, §10.1, étape 4) : l'archive chiffrée remplace le schéma `acma_transit`, après vérification
 * du manifeste. Un échec laisse le transit précédent intact ; les fichiers en clair sont toujours supprimés.
 */
final class AcmaCharger extends Command
{
    use CommandeAcma;

    protected $signature = 'acma:charger
        {archive : Archive chiffrée produite par acma:extraire}
        {--auteur= : E-mail du compte super_admin ou admin qui porte le chargement}';

    protected $description = 'Charge une archive d\'ACMA dans le schéma de transit acma_transit (remplace le précédent)';

    public function handle(ChargementTransit $chargement): int
    {
        $auteur = $this->auteurAcma();
        if ($auteur === null) {
            return self::FAILURE;
        }
        $phrase = $this->phraseAcma(false);
        if ($phrase === null) {
            return self::FAILURE;
        }
        try {
            $resume = $chargement->charger((string) $this->argument('archive'), $phrase, $auteur);
        } catch (Throwable $e) {
            // Aucune donnée à la console : message de la reprise, ou SQLSTATE, table et contrainte seulement.
            $this->error(ErreurAcma::sansDonnees($e, 'du chargement')->getMessage());

            return self::FAILURE;
        }
        $this->info("Transit chargé : {$resume['tables']} tables, {$resume['lignes']} lignes.");
        $this->line('Empreinte du manifeste : '.$resume['manifeste_sha256']);

        return self::SUCCESS;
    }
}
