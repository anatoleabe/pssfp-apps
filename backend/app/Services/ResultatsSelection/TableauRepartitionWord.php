<?php

declare(strict_types=1);

namespace App\Services\ResultatsSelection;

use App\Services\ResultatsSelection\WordGabarit as G;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\Table;

/**
 * Tableaux de répartition du PV, au format des PV précédents : en-tête grisé
 * répété sur chaque page, ligne de total, pourcentages et total général.
 */
final class TableauRepartitionWord
{
    private const NUMERO = 600;

    public static function titre(Section $section, string $titre): void
    {
        $section->addText($titre, ['bold' => true, 'size' => 10], G::centre(['spaceBefore' => 200, 'spaceAfter' => 60, 'keepNext' => true]));
    }

    /**
     * Une ligne par spécialité ; colonnes = critère (pays, genre…). Avec
     * `$pourcentages`, une ligne « EN % DU TOTAL » remplace la colonne TOTAL.
     */
    public static function parSpecialite(Section $section, string $titre, Repartition $rep, string $entete, bool $pourcentages): void
    {
        self::titre($section, $titre);

        $colonnes = array_keys($rep->colonnes);
        $nbValeurs = count($colonnes) + ($pourcentages ? 0 : 1);
        $largeurValeur = min(1400, intdiv(G::LARGEUR - self::NUMERO - 3000, max(1, $nbValeurs)));
        $largeurLibelle = G::LARGEUR - self::NUMERO - $largeurValeur * $nbValeurs;

        $table = G::grille($section);
        $enTete = [['N°', self::NUMERO], [$entete, $largeurLibelle]];
        foreach ($rep->colonnes as $libelle) {
            $enTete[] = [$libelle, $largeurValeur];
        }
        if (! $pourcentages) {
            $enTete[] = ['TOTAL', $largeurValeur];
        }
        G::enTete($table, $enTete);

        foreach (array_keys($rep->lignes) as $i => $ligne) {
            $cellules = [[(string) ($i + 1), self::NUMERO, true], [$ligne, $largeurLibelle]];
            foreach ($colonnes as $col) {
                $cellules[] = [(string) $rep->valeur($ligne, $col), $largeurValeur, true];
            }
            if (! $pourcentages) {
                $cellules[] = [(string) $rep->totalLigne($ligne), $largeurValeur, true];
            }
            G::ligne($table, $cellules);
        }

        $totaux = [['', self::NUMERO], ['TOTAL', $largeurLibelle]];
        foreach ($colonnes as $col) {
            $totaux[] = [(string) $rep->totalColonne($col), $largeurValeur, true];
        }
        if (! $pourcentages) {
            $totaux[] = [(string) $rep->total(), $largeurValeur, true];
        }
        G::ligne($table, $totaux, total: true);

        if ($pourcentages) {
            $parts = [['', self::NUMERO], ['EN % DU TOTAL', $largeurLibelle]];
            foreach ($colonnes as $col) {
                $parts[] = [$rep->pourcentage($col), $largeurValeur, true];
            }
            G::ligne($table, $parts, total: true);
        }

        self::totalGeneral($table, $pourcentages ? 'TOTAL GENERAL' : 'TOTAL DEPOT PHYSIQUE DES DOSSIERS',
            $largeurLibelle, $largeurValeur * $nbValeurs, $nbValeurs, $rep->total());
    }

    /**
     * Une ligne par valeur du critère (statut, tranche d'âge) ; colonnes de
     * détail, puis TOTAL et part de la ligne dans l'ensemble.
     */
    public static function parLignes(Section $section, string $titre, Repartition $rep, string $entete): void
    {
        self::titre($section, $titre);

        $colonnes = array_keys($rep->colonnes);
        $nbValeurs = count($colonnes) + 2;
        $largeurValeur = min(1200, intdiv(G::LARGEUR - self::NUMERO - 3400, $nbValeurs));
        $largeurLibelle = G::LARGEUR - self::NUMERO - $largeurValeur * $nbValeurs;

        $table = G::grille($section);
        $enTete = [['N°', self::NUMERO], [$entete, $largeurLibelle]];
        foreach ($rep->colonnes as $libelle) {
            $enTete[] = [$libelle, $largeurValeur];
        }
        $enTete[] = ['TOTAL', $largeurValeur];
        $enTete[] = ['%', $largeurValeur];
        G::enTete($table, $enTete);

        foreach (array_keys($rep->lignes) as $i => $ligne) {
            $cellules = [[(string) ($i + 1), self::NUMERO, true], [$ligne, $largeurLibelle]];
            foreach ($colonnes as $col) {
                $cellules[] = [(string) $rep->valeur($ligne, $col), $largeurValeur, true];
            }
            $cellules[] = [(string) $rep->totalLigne($ligne), $largeurValeur, true];
            $cellules[] = [$rep->partLigne($ligne), $largeurValeur, true];
            G::ligne($table, $cellules);
        }

        $totaux = [['', self::NUMERO], ['TOTAL', $largeurLibelle]];
        foreach ($colonnes as $col) {
            $totaux[] = [(string) $rep->totalColonne($col), $largeurValeur, true];
        }
        $totaux[] = [(string) $rep->total(), $largeurValeur, true];
        $totaux[] = [$rep->total() === 0 ? '0 %' : '100 %', $largeurValeur, true];
        G::ligne($table, $totaux, total: true);
    }

    private static function totalGeneral(Table $table, string $libelle, int $largeurLibelle, int $largeurValeurs, int $fusion, int $total): void
    {
        $fond = ['bgColor' => 'F2F2F2', 'valign' => 'center'];
        $table->addRow(null, ['cantSplit' => true]);
        $table->addCell(self::NUMERO, $fond);
        $table->addCell($largeurLibelle, $fond)->addText($libelle, G::police(bold: true), ['spaceAfter' => 0]);
        $table->addCell($largeurValeurs, $fond + ['gridSpan' => $fusion])->addText((string) $total, G::police(bold: true), G::CENTRE);
    }
}
