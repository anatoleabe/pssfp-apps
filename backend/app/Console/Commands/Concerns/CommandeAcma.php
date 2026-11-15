<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Models\User;

/**
 * Commandes de la reprise d'ACMA (lot 15) : pas de permission, mais un `--auteur` super_admin ou admin, comme
 * `scolarite:importer-auditeurs`, et une phrase de passe jamais passée en option (historique du shell).
 */
trait CommandeAcma
{
    private function auteurAcma(): ?User
    {
        $auteur = User::query()->where('email', (string) $this->option('auteur'))->first();
        if ($auteur === null || ! $auteur->hasAnyRole(['super_admin', 'admin'])) {
            $this->error('--auteur doit être l\'e-mail d\'un compte super_admin ou admin.');

            return null;
        }

        return $auteur;
    }

    /** Phrase de passe : variable d'environnement ACMA_PHRASE du processus, sinon saisie masquée. */
    private function phraseAcma(bool $confirmer): ?string
    {
        $phrase = getenv('ACMA_PHRASE');
        if (is_string($phrase) && $phrase !== '') {
            return $phrase;
        }
        $phrase = (string) $this->secret('Phrase de passe de l\'archive');
        if ($confirmer && $phrase !== (string) $this->secret('Confirmer la phrase de passe')) {
            $this->error('Les deux saisies diffèrent.');

            return null;
        }

        return $phrase;
    }
}
