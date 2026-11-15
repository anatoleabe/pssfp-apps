<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\CommandeAcma;
use App\Services\Acma\EcartsAcma2026;
use App\Services\Acma\ErreurAcma;
use Illuminate\Console\Command;
use Throwable;

/**
 * Lot 15c (spec du lot 15, §9) : rapport des écarts 2026-2027 entre ACMA et la plateforme, en lecture seule. Le classeur
 * (nominatif) va dans le stockage privé ; la console n'affiche que la synthèse chiffrée.
 */
final class AcmaEcarts2026 extends Command
{
    use CommandeAcma;

    protected $signature = 'acma:ecarts-2026
        {--auteur= : E-mail du compte super_admin ou admin qui produit le rapport}
        {--sortie= : Fichier .xlsx où écrire le classeur (stockage privé par défaut)}';

    protected $description = 'Rapport des écarts 2026-2027 entre ACMA et la plateforme (lecture seule)';

    public function handle(EcartsAcma2026 $ecarts): int
    {
        $auteur = $this->auteurAcma();
        if ($auteur === null) {
            return self::FAILURE;
        }
        try {
            $r = $ecarts->produire($auteur, $this->option('sortie') ?: null);
        } catch (Throwable $e) {
            // Aucune donnée à la console : message de la reprise, ou SQLSTATE, table et contrainte seulement.
            $this->error(ErreurAcma::sansDonnees($e, 'du rapport des écarts')->getMessage());

            return self::FAILURE;
        }
        $this->table(array_keys($r['synthese'][0]), array_map('array_values', $r['synthese']));
        $this->table(['Catégorie', 'Lignes'], array_map(fn (string $k, int $v): array => [$k, $v], array_keys($r['comptes']), $r['comptes']));
        $this->line('Classeur (nominatif) : '.$r['chemin']);

        return self::SUCCESS;
    }
}
