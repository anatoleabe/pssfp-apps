<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\Auditeur;
use App\Models\Versement;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Export Excel de l'analyse de l'espace Finances (spec §6.1, sous-lot F3), en trois feuilles : « Synthèse » (critères puis
 * le tableau affiché, avec sa ligne de total recalculée), « Auditeurs » (une ligne par inscription) et « Versements » (ceux
 * du périmètre). Les montants sont des nombres ; les textes sont écrits comme du texte, jamais interprétés comme une
 * formule.
 */
final class AnalyseFinancesExcel
{
    /** Colonnes de la synthèse : clé de l'indicateur => libellé. */
    public const COLONNES = [
        'inscrits' => 'Inscrits', 'du_brut' => 'Dû brut', 'remises' => 'Remises', 'du' => 'Dû', 'verse' => 'Versé', 'a_confirmer' => 'Dont à confirmer',
        'reste' => 'Reste', 'taux' => 'Taux de recouvrement (%)', 'exigible' => 'Exigible', 'impaye_echu' => 'Impayé échu',
        'a_jour' => 'À jour', 'en_retard' => 'En retard', 'soldes' => 'Soldés',
    ];

    public const ENTETES_AUDITEURS = ['Matricule', 'Nom', 'Prénom', 'Promotion', 'Année', 'Niveau', 'Parcours', 'Statut', 'Financement', 'Dû',
        'Versé', 'Dont à confirmer', 'Reste', 'Exigible', 'Impayé échu', 'Situation', 'Ancienneté de l\'impayé'];

    public function __construct(
        private readonly IndicateursFinanciers $indicateurs,
        private readonly VersementsExcel $versements,
    ) {}

    /**
     * @param  Collection<int, array<string, mixed>>  $lignes
     * @param  list<string>  $dimensions
     * @param  array<string, string>  $criteres  libellés des critères, rappelés en tête
     */
    public function generer(Collection $lignes, array $dimensions, array $criteres): string
    {
        $classeur = new Spreadsheet;
        $this->synthese($classeur->getActiveSheet(), $lignes, $dimensions, $criteres);
        $this->auditeurs($classeur->createSheet(), $lignes);
        $feuille = $classeur->createSheet();
        $feuille->setTitle('Versements');
        $requete = Versement::query()->whereIn('inscription_annuelle_id', $lignes->pluck('inscription_id')->all())->orderBy('id');
        $source = VersementsExcel::lireVersements($requete);
        // Au-delà du plafond, le détail ne tient pas en mémoire : il se prend dans la liste des versements, par filtres.
        self::remplir($feuille, VersementsExcel::ENTETES_VERSEMENTS, $source === null
            ? [['Plus de '.number_format(VersementsExcel::plafond(), 0, ',', ' ').' versements : exportez le détail depuis Finances → Versements, par filtres.']]
            : (function () use ($source): \Generator {
                foreach ($source as $v) {
                    yield VersementsExcel::ligneVersement($v);
                }
            })());

        ob_start();
        (new Xlsx($classeur))->save('php://output');

        return (string) ob_get_clean();
    }

    /**
     * Lignes de la synthèse : une par groupe, puis le total recalculé sur tout le périmètre.
     *
     * @param  Collection<int, array<string, mixed>>  $lignes
     * @param  list<string>  $dimensions
     * @return list<array{cles: list<string>, indicateurs: array<string, int|float|null>}>
     */
    public function tableau(Collection $lignes, array $dimensions): array
    {
        return [...$this->indicateurs->ventilation($lignes, $dimensions),
            ['cles' => array_map(fn (): string => 'Total', $dimensions ?: ['total']), 'indicateurs' => $this->indicateurs->indicateurs($lignes, false)]];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lignes
     * @param  list<string>  $dimensions
     * @param  array<string, string>  $criteres
     */
    private function synthese(Worksheet $feuille, Collection $lignes, array $dimensions, array $criteres): void
    {
        $feuille->setTitle('Synthèse');
        $n = 1;
        foreach ($criteres as $libelle => $valeur) {
            $feuille->setCellValueExplicit('A'.$n, $libelle, DataType::TYPE_STRING);
            $feuille->setCellValueExplicit('B'.$n, $valeur, DataType::TYPE_STRING);
            $n++;
        }
        $n++;
        $entetes = [...array_map(fn (string $d): string => IndicateursFinanciers::DIMENSIONS[$d], $dimensions ?: []), ...array_values(self::COLONNES)];
        if ($dimensions === []) {
            array_unshift($entetes, 'Périmètre');
        }
        $source = collect($this->tableau($lignes, $dimensions))->map(fn (array $g): array => [
            ...$g['cles'],
            ...array_map(fn (string $k): int|float|null => $g['indicateurs'][$k], array_keys(self::COLONNES)),
        ]);
        self::remplir($feuille, $entetes, $source, $n);
    }

    /** @param  Collection<int, array<string, mixed>>  $lignes */
    private function auditeurs(Worksheet $feuille, Collection $lignes): void
    {
        $feuille->setTitle('Auditeurs');
        self::remplir($feuille, self::ENTETES_AUDITEURS, $lignes->map(fn (array $l): array => [
            $l['matricule'], $l['nom'], $l['prenom'], $l['promotion'] === '' ? null : (int) $l['promotion'], $l['annee'], $l['niveau'], $l['parcours'],
            Auditeur::STATUTS[$l['statut']] ?? $l['statut'], IndicateursFinanciers::FINANCEMENTS[$l['financement']],
            $l['du'], $l['verse'], $l['a_confirmer'], $l['reste'], $l['exigible'], $l['impaye_echu'],
            LibelleSituation::LIBELLES[$l['situation']] ?? $l['situation'],
            $l['anciennete'] === null ? null : IndicateursFinanciers::ANCIENNETES[$l['anciennete']],
        ]));
    }

    /**
     * @param  list<string>  $entetes
     * @param  iterable<list<mixed>>  $lignes
     */
    private static function remplir(Worksheet $feuille, array $entetes, iterable $lignes, int $debut = 1): void
    {
        $derniere = Coordinate::stringFromColumnIndex(count($entetes));
        $feuille->fromArray($entetes, null, 'A'.$debut);
        $feuille->getStyle('A'.$debut.':'.$derniere.$debut)->getFont()->setBold(true);
        $n = $debut + 1;
        foreach ($lignes as $ligne) {
            foreach (array_values($ligne) as $i => $valeur) {
                $cellule = Coordinate::stringFromColumnIndex($i + 1).$n;
                if (is_int($valeur) || is_float($valeur)) {
                    $feuille->setCellValue($cellule, $valeur);
                } elseif ($valeur !== null) {
                    $feuille->setCellValueExplicit($cellule, (string) $valeur, DataType::TYPE_STRING);
                }
            }
            $n++;
        }
        foreach (range(1, count($entetes)) as $i) {
            $feuille->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }
    }
}
