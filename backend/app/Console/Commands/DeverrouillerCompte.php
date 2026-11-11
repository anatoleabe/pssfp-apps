<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Audit\VerrouillageComptes;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Lot 14d-2 : secours de l'exploitant du serveur, quand plus aucun administrateur ne peut se connecter pour lever un verrou.
 * Journalisé comme une levée depuis l'écran ; le titulaire choisit un nouveau mot de passe.
 */
final class DeverrouillerCompte extends Command
{
    protected $signature = 'comptes:deverrouiller {email : Adresse du compte} {--motif= : Motif (10 caractères au moins)}';

    protected $description = 'Lève le verrou d\'un compte du personnel (secours) et envoie un lien pour choisir un nouveau mot de passe';

    public function handle(VerrouillageComptes $verrous): int
    {
        $compte = User::query()->where('email', (string) $this->argument('email'))->first();
        if ($compte === null) {
            $this->error('Compte introuvable.');

            return self::FAILURE;
        }
        try {
            $verrous->deverrouiller($compte, null, (string) $this->option('motif'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info('Compte déverrouillé ; un lien pour choisir un nouveau mot de passe est parti à '.$compte->email.'.');

        return self::SUCCESS;
    }
}
