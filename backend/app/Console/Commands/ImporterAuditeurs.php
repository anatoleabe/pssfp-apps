<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Import des auditeurs des promotions antérieures depuis les fichiers « Full data » de la scolarité : **retiré** le
 * 16/11/2026 (spec de la réinscription des anciens, Q9). L'import de la P13 est fait. Un nouvel auditeur n'entre que
 * par la candidature, dans la promotion en cours ; un ancien se réinscrit depuis son dossier d'archive ACMA. La
 * signature reste, pour qu'un appel par habitude échoue avec un message au lieu d'une commande inconnue.
 */
final class ImporterAuditeurs extends Command
{
    protected $signature = 'scolarite:importer-auditeurs
        {--source=* : (retiré)}
        {--admis= : (retiré)}
        {--annee= : (retiré)}
        {--niveau= : (retiré)}
        {--auteur= : (retiré)}
        {--appliquer : (retiré)}';

    protected $description = 'Retirée : un ancien auditeur se réinscrit depuis son dossier d\'archive ACMA';

    public function handle(): int
    {
        $this->error('Commande retirée : un ancien auditeur se réinscrit depuis son dossier d\'archive ACMA (Scolarité > Archives ACMA) ; un nouvel auditeur n\'entre que par la candidature.');

        return self::FAILURE;
    }
}
