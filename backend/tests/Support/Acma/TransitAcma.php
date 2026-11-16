<?php

declare(strict_types=1);

namespace Tests\Support\Acma;

use App\Services\Acma\ChargementTransit;
use App\Services\Acma\ExtractionAcma;

/**
 * Fabrique du schéma `acma_transit` de test (spec du lot 15, §14) : le jeu fictif, chargé par le même code que
 * `acma:charger`, dans la transaction de RefreshDatabase (`CREATE SCHEMA` est transactionnel sous PostgreSQL).
 */
final class TransitAcma
{
    /** @param  array<string, list<array<string, string|null>>>|null  $lignes */
    public static function construire(?array $lignes = null): void
    {
        $tables = JeuAcmaFictif::tables($lignes);
        $definitions = [];
        foreach (ExtractionAcma::TABLES as $table) {
            $exclues = ExtractionAcma::COLONNES_EXCLUES[$table] ?? [];
            $colonnes = array_values(array_filter(array_column($tables[$table]['colonnes'], 'nom'), fn (string $c): bool => ! in_array($c, $exclues, true)));
            $definitions[$table] = ['fichier' => $table.'.csv', 'colonnes' => $colonnes, 'lignes' => count($tables[$table]['lignes']), 'sha256' => str_repeat('0', 64)];
        }
        $source = new SourceAcmaFactice($tables);
        // Comme l'extraction : les colonnes binaires passent en hexadécimal.
        $hexa = function (string $table, array $definition) use ($source, $tables): \Generator {
            $types = array_column($tables[$table]['colonnes'], 'type', 'nom');
            foreach ($source->lignes($table, $definition['colonnes'], []) as $ligne) {
                foreach ($definition['colonnes'] as $i => $c) {
                    if ($ligne[$i] !== null && str_contains($types[$c], 'binary')) {
                        $ligne[$i] = bin2hex($ligne[$i]);
                    }
                }
                yield $ligne;
            }
        };
        app(ChargementTransit::class)->remplacerSchema(
            $definitions,
            $hexa,
            ['format' => ExtractionAcma::FORMAT, 'source' => ['fichier' => 'jeu-fictif.sql', 'date_export' => '2026-11-15']],
            str_repeat('a', 64),
            'jeu-fictif.chiffre',
            null,
        );
    }

    /** Jeu fictif modifié par $modifier(&$lignes). */
    public static function construireAvec(callable $modifier): void
    {
        $lignes = JeuAcmaFictif::lignes();
        $modifier($lignes);
        self::construire($lignes);
    }
}
