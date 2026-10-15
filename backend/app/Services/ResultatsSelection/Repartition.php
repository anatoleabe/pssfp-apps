<?php

declare(strict_types=1);

namespace App\Services\ResultatsSelection;

/**
 * Tableau croisé du PV (spécialité × pays, statut × spécialité, tranche d'âge
 * × genre…), avec ses totaux de ligne, de colonne et ses pourcentages.
 */
final readonly class Repartition
{
    /** Clé et libellé des valeurs absentes ou hors liste. */
    public const NON_PRECISE = '—';

    /**
     * @param  array<string, string>  $colonnes  clé => libellé d'en-tête
     * @param  array<string, array<string, int>>  $lignes  libellé de ligne => [clé colonne => effectif]
     */
    public function __construct(
        public array $colonnes,
        public array $lignes,
    ) {}

    /**
     * Compte les éléments par ligne et par colonne.
     *
     * Les lignes de `$lignes` sont toujours affichées, même vides, dans cet
     * ordre ; une ligne rencontrée hors de cette liste est ajoutée à la suite.
     * Une valeur de colonne inconnue tombe en « non précisé ». Les colonnes
     * facultatives et « non précisé » n'apparaissent que si un élément en
     * relève : le tableau reste identique aux PV précédents dans le cas normal.
     *
     * @template T
     *
     * @param  iterable<T>  $elements
     * @param  list<string>  $lignes
     * @param  callable(T): string  $ligneDe
     * @param  array<string, string>  $colonnes
     * @param  callable(T): ?string  $colonneDe
     * @param  array<string, string>  $optionnelles
     */
    public static function croiser(
        iterable $elements,
        array $lignes,
        callable $ligneDe,
        array $colonnes,
        callable $colonneDe,
        array $optionnelles = [],
        string $libelleNonPrecise = 'NON PRÉCISÉ',
    ): self {
        $connues = $colonnes + $optionnelles;
        $comptes = array_fill_keys($lignes, []);
        $presentes = [];

        foreach ($elements as $element) {
            $valeur = (string) $colonneDe($element);
            $colonne = array_key_exists($valeur, $connues) ? $valeur : self::NON_PRECISE;
            $ligne = $ligneDe($element);
            $comptes[$ligne][$colonne] = ($comptes[$ligne][$colonne] ?? 0) + 1;
            $presentes[$colonne] = true;
        }

        foreach ($optionnelles + [self::NON_PRECISE => $libelleNonPrecise] as $valeur => $libelle) {
            if (isset($presentes[$valeur]) && ! isset($colonnes[$valeur])) {
                $colonnes[$valeur] = $libelle;
            }
        }

        return new self($colonnes, $comptes);
    }

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
        $totaux = [];
        foreach (array_keys($this->colonnes) as $cle) {
            $totaux[$cle] = $this->totalColonne($cle);
        }

        return (self::arrondir($totaux)[$colonne] ?? 0).' %';
    }

    /** Part d'une ligne dans le total, arrondie à l'unité : « 41 % ». */
    public function partLigne(string $ligne): string
    {
        $totaux = [];
        foreach (array_keys($this->lignes) as $cle) {
            $totaux[$cle] = $this->totalLigne($cle);
        }

        return (self::arrondir($totaux)[$ligne] ?? 0).' %';
    }

    /**
     * Arrondis à l'unité par la méthode du plus fort reste, pour que les
     * pourcentages fassent toujours 100 : arrondis séparément, 47,5 et 52,5
     * donneraient 48 + 53 = 101.
     *
     * @param  array<string, int>  $effectifs
     * @return array<string, int>
     */
    private static function arrondir(array $effectifs): array
    {
        $total = array_sum($effectifs);
        if ($total === 0) {
            return array_map(fn (): int => 0, $effectifs);
        }

        $exacts = array_map(fn (int $n): float => $n * 100 / $total, $effectifs);
        $arrondis = array_map(fn (float $p): int => (int) floor($p), $exacts);
        $restes = array_map(fn (float $p): float => $p - floor($p), $exacts);
        arsort($restes);

        $manquant = 100 - array_sum($arrondis);
        foreach (array_keys($restes) as $cle) {
            if ($manquant <= 0) {
                break;
            }
            $arrondis[$cle]++;
            $manquant--;
        }

        return $arrondis;
    }
}
