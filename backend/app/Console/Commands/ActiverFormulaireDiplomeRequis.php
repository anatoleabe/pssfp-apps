<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bascule le formulaire de candidature vers sa version 2 (bloc « diplôme
 * requis » et blocs répétables), cf. docs/specs/module-5-evolution-diplomes-2026-08.md.
 *
 * La bascule est un acte de déploiement distinct de la migration : elle doit
 * être jouée APRÈS la mise en ligne du bundle candidature. Entre les deux, les
 * nouveaux dossiers restent en version 1 et donc pleinement soumissibles avec
 * l'ancien écran, qui est encore celui servi aux candidats.
 *
 * Les dossiers déjà créés ne sont jamais touchés : seule la valeur par défaut
 * des futures insertions change.
 */
final class ActiverFormulaireDiplomeRequis extends Command
{
    protected $signature = 'candidatures:activer-formulaire-v2
                            {--desactiver : Revenir à la version 1 pour les futurs dossiers}';

    protected $description = 'Active (ou désactive) le formulaire de candidature v2 pour les nouveaux dossiers';

    public function handle(): int
    {
        $version = $this->option('desactiver') ? 1 : 2;

        DB::statement("ALTER TABLE candidatures ALTER COLUMN form_version SET DEFAULT {$version}");

        $actuel = DB::selectOne(
            "select column_default from information_schema.columns
             where table_name = 'candidatures' and column_name = 'form_version'"
        );

        $this->info("Les nouveaux dossiers seront créés en form_version {$version}.");
        $this->line('Défaut effectif en base : '.($actuel->column_default ?? 'inconnu'));
        $this->line('Les dossiers existants ne sont pas modifiés.');

        return self::SUCCESS;
    }
}
