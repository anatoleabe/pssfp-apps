<?php

declare(strict_types=1);

namespace App\Services\ResultatsSelection;

/**
 * Tableau croisé « spécialité × critère » du PV (pays, genre ou mode de
 * formation), avec ses totaux de ligne, de colonne et ses pourcentages.
 */
final readonly class Repartition
{
    /**
     * @param  array<string, string>  $colonnes  clé => libellé d'en-tête
     * @param  array<string, array<string, int>>  $lignes  spécialité => [clé colonne => effectif]
     */
    public function __construct(
        public array $colonnes,
        public array $lignes,
    ) {}

    public function valeur(string $ligne, string $colonne): int
    {
        return $this->lignes[$ligne][$colonne] ?? 0;
    }

    public function totalLigne(string $ligne): int
    {
        return array_sum($this->lignes[$ligne] ?? []);
    }

    public function totalColonne(string $colonne): int
    {
        return array_sum(array_map(fn (array $ligne): int => $ligne[$colonne] ?? 0, $this->lignes));
    }

    public function total(): int
    {
        return array_sum(array_map('array_sum', $this->lignes));
    }

    /** Part d'une colonne dans le total, arrondie à l'unité : « 45 % ». */
    public function pourcentage(string $colonne): string
    {
        return ($this->pourcentages()[$colonne] ?? 0).' %';
    }

    /**
     * Arrondis à l'unité par la méthode du plus fort reste, pour que la ligne
     * « EN % DU TOTAL » fasse toujours 100 : arrondis séparément, 47,5 et 52,5
     * donneraient 48 + 53 = 101.
     *
     * @return array<string, int>
     */
    private function pourcentages(): array
    {
        $total = $this->total();
        if ($total === 0) {
            return array_fill_keys(array_keys($this->colonnes), 0);
        }

        $exacts = [];
        foreach (array_keys($this->colonnes) as $colonne) {
            $exacts[$colonne] = $this->totalColonne($colonne) * 100 / $total;
        }

        $arrondis = array_map(fn (float|int $p): int => (int) floor($p), $exacts);
        $restes = array_map(fn (float|int $p): float => $p - floor($p), $exacts);
        arsort($restes);

        $manquant = 100 - array_sum($arrondis);
        foreach (array_keys($restes) as $colonne) {
            if ($manquant <= 0) {
                break;
            }
            $arrondis[$colonne]++;
            $manquant--;
        }

        return $arrondis;
    }
}
