<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CampagneCandidature;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\Ecampus\EchecEcampus;
use Illuminate\Console\Command;

/**
 * Note dans le portail l'état des comptes eCampus déjà créés. Lecture seule sur
 * eCampus : aucun compte n'y est créé ni modifié.
 */
final class RapprocherComptesEcampus extends Command
{
    protected $signature = 'ecampus:rapprocher {--campagne= : Identifiant de la campagne (toutes par défaut)}';

    protected $description = 'Note les comptes eCampus déjà présents, sans rien écrire sur eCampus';

    public function handle(ComptesEcampus $comptes): int
    {
        if (! ComptesEcampus::actif()) {
            $this->error('ECAMPUS_JETON n\'est pas configuré.');

            return self::FAILURE;
        }

        $campagnes = CampagneCandidature::query()
            ->when($this->option('campagne'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        try {
            foreach ($campagnes as $campagne) {
                $bilan = $comptes->rapprocher($campagne);
                $this->info("Campagne {$campagne->id} : {$bilan['existants']} compte(s) existant(s), dont {$bilan['ecarts']} avec une différence ; {$bilan['absents']} absent(s) ; {$bilan['a_finir']} à terminer.");
            }
        } catch (EchecEcampus $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
