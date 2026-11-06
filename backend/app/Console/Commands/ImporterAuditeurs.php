<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Scolarite\ImportAuditeurs;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Import des auditeurs des promotions antérieures (fichiers « Full data » de la
 * scolarité). Essai à blanc par défaut : rien n'est écrit sans --appliquer.
 * Le rapport ne cite que des matricules, jamais de nom ni de coordonnée.
 */
final class ImporterAuditeurs extends Command
{
    protected $signature = 'scolarite:importer-auditeurs
        {--source=* : Fichier « Full data » (xlsx/xls), dans l\'ordre de priorité}
        {--admis= : Liste des admis (docx de la scolarité, ou texte : un matricule par ligne)}
        {--annee=2026-2027 : Année de l\'inscription des admis}
        {--niveau=M2 : Niveau de l\'inscription des admis}
        {--auteur= : E-mail du compte d\'administration qui porte l\'import}
        {--appliquer : Écrire en base (sinon essai à blanc)}';

    protected $description = 'Importe les auditeurs des promotions antérieures depuis les fichiers de la scolarité';

    public function handle(ImportAuditeurs $import): int
    {
        $auteur = User::query()->where('email', (string) $this->option('auteur'))->first();
        if ($auteur === null) {
            $this->error('Compte --auteur introuvable.');

            return self::FAILURE;
        }
        $sources = (array) $this->option('source');
        foreach ($sources as $s) {
            if (! is_readable($s)) {
                $this->error("Source illisible : {$s}");

                return self::FAILURE;
            }
        }
        $admis = filled($this->option('admis')) ? ImportAuditeurs::listeAdmis((string) $this->option('admis')) : [];
        $appliquer = (bool) $this->option('appliquer');

        try {
            $b = $import->importer($sources, $admis, (string) $this->option('annee'), (string) $this->option('niveau'), $auteur, $appliquer);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(($appliquer ? 'Import' : 'Essai à blanc').' : '.$b['lus'].' matricules lus, '.count($admis).' admis.');
        $this->line('  à créer / créés : '.$b['crees'].' (dont '.$b['inscrits'].' inscrits '.$this->option('niveau').' '.$this->option('annee').')');
        foreach (['deja' => 'déjà présents', 'sans_telephone' => 'sans téléphone exploitable', 'compte_occupe' => 'téléphone d\'un compte du personnel', 'telephone_en_double' => 'téléphone déjà pris par un autre auditeur du fichier', 'autre_personne' => 'téléphone d\'un compte candidat d\'une autre personne', 'date_illisible' => 'date de naissance illisible', 'admis_introuvables' => 'admis absents des sources'] as $cle => $libelle) {
            $this->line('  '.$libelle.' : '.count($b[$cle]).(count($b[$cle]) > 0 ? ' ('.implode(', ', $b[$cle]).')' : ''));
        }
        foreach ($b['erreurs'] as $matricule => $message) {
            $this->warn("  erreur {$matricule} : {$message}");
        }

        return self::SUCCESS;
    }
}
