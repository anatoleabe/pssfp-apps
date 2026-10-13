<?php

declare(strict_types=1);

namespace App\Services\ResultatsSelection;

use App\Services\ResultatsSelection\WordGabarit as G;
use PhpOffice\PhpWord\ComplexType\TblWidth;
use PhpOffice\PhpWord\Element\Cell;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * Communiqué conjoint MINFI / UY2 des résultats, au format Word.
 *
 * Mise en page et polices reprises du communiqué de la 13e promotion
 * (arbitrage du 13 oct. 2026) : en-tête Garamond gras, logos UY2 et PSSFP côte
 * à côte entre les deux blocs institutionnels, titre Times New Roman, référence
 * Bodoni MT Condensed, autorités Berlin Sans FB Demi, signatures Bodoni MT.
 */
final class CommuniqueWord
{
    private const TNR = 'Times New Roman';

    /** Marges du modèle : 2,5 cm à gauche et à droite. */
    private const MARGES = ['marginTop' => 1135, 'marginBottom' => 1276, 'marginLeft' => 1417, 'marginRight' => 1417];

    private const LARGEUR = 11906 - 1417 - 1417;

    /** Paragraphe par défaut du modèle : 10 pt après, interligne 1,15. */
    private const PARAGRAPHE = ['alignment' => Jc::CENTER, 'spaceAfter' => 200, 'lineHeight' => 1.15];

    private const SERRE = ['alignment' => Jc::CENTER, 'spaceAfter' => 0, 'lineHeight' => 1.0];

    public function generer(ResultatsSelection $r): string
    {
        $word = G::document();
        $section = G::section($word, self::MARGES, ['name' => 'Calibri', 'size' => 9, 'color' => '000000']);
        $annee = str_replace('-', ' – ', $r->anneeAcademique);

        $this->entete($section);

        $titre = $section->addTextRun(['spaceBefore' => 240] + self::SERRE);
        $titre->addText('JOINT PRESS RELEASE /', ['name' => self::TNR, 'size' => 20, 'bold' => true]);
        $titre->addTextBreak();
        $titre->addText('COMMUNIQUE CONJOINT', ['name' => self::TNR, 'size' => 20, 'bold' => true]);
        $this->reference($section, $r);
        $section->addTextBreak(1, ['size' => 8]);

        $section->addText("Announcing the results of the call for application for the entrance into the first year of the Master's degree cycle in Public Finance, for the {$annee} Academic year.",
            ['name' => self::TNR, 'size' => 12], self::SERRE);
        $section->addText("Portant publication des résultats de l’appel à candidatures pour l’entrée en première année du cycle de Master en Finances Publiques au titre de l’année académique {$annee}.",
            ['name' => self::TNR, 'size' => 12, 'italic' => true], ['spaceAfter' => 200] + self::SERRE);

        $autorites = ['name' => 'Berlin Sans FB Demi', 'size' => 14, 'bold' => true];
        $section->addText('THE MINISTER OF FINANCE AND THE RECTOR OF THE UNIVERSITY OF YAOUNDE II –SOA', $autorites, self::PARAGRAPHE);
        $section->addText('LE MINISTRE DES FINANCES ET LE RECTEUR DE L’UNIVERSITE DE YAOUNDE II – SOA', $autorites + ['italic' => true], self::PARAGRAPHE);

        $corps = ['name' => self::TNR, 'size' => 13];
        $annonce = $section->addTextRun(self::PARAGRAPHE);
        $annonce->addText('ANNOUNCE / ', $corps + ['bold' => true]);
        $annonce->addText('COMMUNIQUENT', $corps + ['bold' => true, 'italic' => true]);
        $annonce->addText(': ', $corps + ['bold' => true]);

        $section->addText("The following candidates, listed in alphabetical order, are declared admitted to the first year of the Master's degree in Public Finance for the {$r->anneeAcademiqueSlash()} academic year, subject to presentation of their original diplomas.",
            $corps, self::PARAGRAPHE);
        $section->addText("Les candidats dont les noms suivent, classés par ordre alphabétique, sont déclarés admis, sous réserve de la présentation des originaux de leurs diplômes, en première année de Master en Finances Publiques au titre de l’année académique {$r->anneeAcademiqueSlash()}.",
            $corps + ['italic' => true], self::PARAGRAPHE);

        $ilSagit = $section->addTextRun(self::PARAGRAPHE);
        $ilSagit->addText('They are / ', $corps);
        $ilSagit->addText('Il s’agit de', $corps + ['italic' => true]);
        $ilSagit->addText(' : ', $corps);

        $titreListe = ['name' => self::TNR, 'size' => 12, 'bold' => true];
        $section->addText('National candidates / Candidats nationaux :', $titreListe, ['spaceAfter' => 120]);
        $this->liste($section, $r->nationaux, avecNationalite: false);

        $section->addText('Foreign candidates / Candidats étrangers :', $titreListe, ['spaceBefore' => 200, 'spaceAfter' => 120]);
        $this->liste($section, $r->etrangers, avecNationalite: true);

        $legende = ['name' => 'Arial Narrow', 'size' => 11, 'italic' => true];
        $section->addText('(P) = Présentiel/In-person', $legende, ['indentation' => ['left' => 3500], 'spaceBefore' => 200, 'spaceAfter' => 0]);
        $section->addText('(D)= Distanciel/Distance learning', $legende, ['indentation' => ['left' => 3500], 'spaceAfter' => 200]);

        $this->signatures($section);

        return G::enBinaire($word);
    }

    /**
     * « N°26/______MINFI/SG of______ » : blancs soulignés à compléter à la
     * main, comme sur le modèle, sauf si le numéro a été saisi.
     */
    private function reference(Section $section, ResultatsSelection $r): void
    {
        $police = ['name' => 'Bodoni MT Condensed', 'size' => 18, 'bold' => true];
        $blanc = $police + ['underline' => 'single'];
        $vide = str_repeat("\u{00A0}", 22);

        $run = $section->addTextRun(['spaceBefore' => 60] + self::SERRE);
        $run->addText('N°'.$r->seance->anneeReference().'/', $police);
        $run->addText($r->seance->numero ?? $vide, $r->seance->numero === null ? $blanc : $police);
        $run->addText($r->seance->numero === null ? '' : '/', $police);
        $run->addText(config('resultats_selection.reference_communique').' of', $police);
        $run->addText($vide, $blanc);
    }

    /**
     * Quatre blocs côte à côte, aux positions du modèle 13e : texte
     * Université, logos UY2 et PSSFP, texte MINFI. L'en-tête déborde dans les
     * marges, comme sur le modèle.
     */
    private function entete(Section $section): void
    {
        $gauche = (int) Converter::cmToTwip(5.85);
        $logos = (int) Converter::cmToTwip(6.67);
        $droite = (int) Converter::cmToTwip(6.35);

        $table = G::colonnes($section, [
            'width' => $gauche + $logos + $droite,
            'indent' => new TblWidth(-(int) Converter::cmToTwip(0.76)),
        ]);
        $table->addRow();

        $this->blocTexte($table->addCell($gauche), (array) config('resultats_selection.entete_universite'));

        $run = $table->addCell($logos, ['valign' => 'top'])->addTextRun(self::SERRE);
        $run->addImage(G::image('uy2.png'), ['height' => Converter::cmToPoint(2.7)]);
        $run->addText("\u{00A0}\u{00A0}\u{00A0}");
        $run->addImage(G::image('logo.png'), ['width' => Converter::cmToPoint(2.91)]);

        $this->blocTexte($table->addCell($droite), (array) config('resultats_selection.entete_minfi'));
    }

    /** @param  list<array{0: string, 1: int|float}>  $lignes */
    private function blocTexte(Cell $cellule, array $lignes): void
    {
        foreach ($lignes as [$texte, $taille]) {
            $lien = str_starts_with($texte, 'www.');
            $cellule->addText($texte, [
                'name' => 'Garamond',
                'size' => $taille,
                'bold' => ! $lien,
                'color' => $lien ? '0563C1' : '000000',
                'underline' => $lien ? 'single' : 'none',
            ], self::SERRE);
        }
    }

    /** @param  list<CandidatRetenu>  $candidats */
    private function liste(Section $section, array $candidats, bool $avecNationalite): void
    {
        if ($candidats === []) {
            $section->addText('None / Néant', ['name' => self::TNR, 'size' => 12, 'italic' => true], ['indentation' => ['left' => 400]]);

            return;
        }

        $mode = 2000;
        $nationalite = $avecNationalite ? 2000 : 0;
        $nom = self::LARGEUR - 600 - $mode - $nationalite;

        $table = $section->addTable(['borderSize' => 4, 'borderColor' => '000000', 'cellMarginLeft' => 80, 'cellMarginRight' => 80,
            'width' => self::LARGEUR, 'unit' => 'dxa', 'layout' => 'fixed']);
        G::enTete($table, array_values(array_filter([
            ['N°', 600],
            ['FULL NAME / NOMS ET PRENOMS', $nom],
            $avecNationalite ? ['NATIONALITY / NATIONALITE', $nationalite] : null,
            ['TRAINING METHOD / MODE FORMATION', $mode],
        ])), 10);

        foreach ($candidats as $i => $c) {
            G::ligne($table, array_values(array_filter([
                [(string) ($i + 1), 600, true],
                [$c->nomComplet(), $nom],
                $avecNationalite ? [$c->nationalite, $nationalite, true] : null,
                [$c->mode, $mode, true],
            ])), taille: 10);
        }
    }

    private function signatures(Section $section): void
    {
        $section->addText('Yaoundé, le…………………………………..', ['name' => 'Arial Narrow', 'size' => 11],
            ['alignment' => Jc::END, 'spaceAfter' => 200, 'keepNext' => true]);

        // Comme le modèle, le bloc déborde dans les marges (−709 / −567 twips)
        // pour que chaque titre tienne sur une ligne.
        $recteur = 6100;
        $ministre = self::LARGEUR + 709 + 567 - $recteur;
        $table = G::colonnes($section, ['width' => $recteur + $ministre, 'indent' => new TblWidth(-709)]);
        $table->addRow(null, ['cantSplit' => true]);
        $signature = ['name' => 'Bodoni MT', 'size' => 14, 'underline' => 'thick'];
        foreach ([
            ['The Rector of the University of Yaoundé II', 'Le Recteur de l’Université de Yaoundé II', $recteur],
            ['The Minister of Finance', 'Le Ministre des Finances', $ministre],
        ] as [$anglais, $francais, $largeur]) {
            $cellule = $table->addCell($largeur);
            $cellule->addText($anglais, $signature + ['bold' => true], self::SERRE);
            $cellule->addText($francais, $signature + ['italic' => true], self::SERRE);
            $cellule->addTextBreak(5, ['size' => 11]);
        }
    }
}
