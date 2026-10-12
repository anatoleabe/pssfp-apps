<?php

declare(strict_types=1);

namespace App\Services\ResultatsSelection;

use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Language;
use RuntimeException;

/**
 * Mise en page Word commune au communiqué et au PV : A4, Times New Roman pour
 * le texte, Arial pour les tableaux (polices présentes sur tous les postes du
 * MINFI, le document devant rester modifiable avant impression).
 */
final class WordGabarit
{
    public const TEXTE = 'Times New Roman';

    public const TABLEAU = 'Arial';

    /** Largeur utile : 21 cm − 2 × 1,8 cm de marge. */
    public const LARGEUR = 9865;

    public const CENTRE = ['alignment' => Jc::CENTER, 'spaceAfter' => 0];

    public const JUSTIFIE = ['alignment' => Jc::BOTH, 'spaceAfter' => 120, 'lineHeight' => 1.15];

    /** Largeurs de colonnes imposées : Word ne les recalcule pas selon le contenu. */
    public const LARGEUR_FIXE = ['width' => self::LARGEUR, 'unit' => 'dxa', 'layout' => 'fixed'];

    private const BORDURE = ['borderSize' => 4, 'borderColor' => '333333', 'cellMarginLeft' => 80,
        'cellMarginRight' => 80, 'cellMarginTop' => 20, 'cellMarginBottom' => 20];

    /**
     * Paragraphe centré avec surcharges. `array_merge` et non `+` : l'union
     * garderait le `spaceAfter => 0` de CENTRE au lieu de la valeur voulue.
     *
     * @param  array<string, mixed>  $surcharges
     * @return array<string, mixed>
     */
    public static function centre(array $surcharges = []): array
    {
        return array_merge(self::CENTRE, $surcharges);
    }

    public static function document(): PhpWord
    {
        // Les noms et fonctions sont des données : « & » ou « < » doivent
        // produire un XML valide, pas un document corrompu.
        Settings::setOutputEscapingEnabled(true);

        $word = new PhpWord;
        $word->setDefaultFontName(self::TEXTE);
        $word->setDefaultFontSize(11);
        $word->getSettings()->setThemeFontLang(new Language(Language::FR_FR));
        $word->getDocInfo()->setCreator('PSSFP')->setCompany('Programme Supérieur de Spécialisation en Finances Publiques');

        return $word;
    }

    public static function section(PhpWord $word): Section
    {
        $section = $word->addSection([
            'paperSize' => 'A4',
            'marginTop' => Converter::cmToTwip(1.4),
            'marginBottom' => Converter::cmToTwip(2.2),
            'marginLeft' => Converter::cmToTwip(1.8),
            'marginRight' => Converter::cmToTwip(1.8),
            'footerHeight' => Converter::cmToTwip(0.8),
        ]);

        $pied = $section->addFooter();
        foreach ((array) config('resultats_selection.pied_de_page') as $i => $ligne) {
            $pied->addText($ligne, ['name' => self::TABLEAU, 'size' => 7.5, 'bold' => $i > 0, 'color' => '444444'], self::CENTRE);
        }
        $pied->addPreserveText('{PAGE} / {NUMPAGES}', ['name' => self::TABLEAU, 'size' => 8, 'color' => '555555'],
            ['alignment' => Jc::END, 'spaceAfter' => 0]);

        return $section;
    }

    /** Tableau à bordures ; la première ligne (en-tête grisé) se répète sur chaque page. */
    public static function grille(Section $section): Table
    {
        return $section->addTable(self::BORDURE + self::LARGEUR_FIXE);
    }

    /** @param  list<array{0: string, 1: int}>  $colonnes  [libellé, largeur en twips] */
    public static function enTete(Table $table, array $colonnes): void
    {
        $table->addRow(null, ['tblHeader' => true, 'cantSplit' => true]);
        foreach ($colonnes as [$libelle, $largeur]) {
            $table->addCell($largeur, ['bgColor' => 'E6E6E6', 'valign' => 'center'])
                ->addText($libelle, self::police(bold: true), self::CENTRE);
        }
    }

    /**
     * @param  list<array{0: string, 1: int, 2?: bool}>  $cellules  [texte, largeur, centré]
     */
    public static function ligne(Table $table, array $cellules, bool $total = false): void
    {
        $table->addRow(null, ['cantSplit' => true]);
        foreach ($cellules as $cellule) {
            [$texte, $largeur] = $cellule;
            $alignement = ($cellule[2] ?? false) ? Jc::CENTER : Jc::START;
            $table->addCell($largeur, $total ? ['bgColor' => 'F2F2F2', 'valign' => 'center'] : ['valign' => 'center'])
                ->addText($texte, self::police(bold: $total), ['alignment' => $alignement, 'spaceAfter' => 0]);
        }
    }

    /** @return array<string, mixed> */
    public static function police(bool $bold = false, bool $italic = false, float $size = 9.5): array
    {
        return ['name' => self::TABLEAU, 'size' => $size, 'bold' => $bold, 'italic' => $italic];
    }

    /** Tableau sans bordure, utile pour poser deux blocs côte à côte. */
    public static function colonnes(Section $section): Table
    {
        return $section->addTable(['borderSize' => 0, 'borderColor' => 'FFFFFF'] + self::LARGEUR_FIXE);
    }

    public static function image(string $fichier): string
    {
        return resource_path('images/pdf/'.$fichier);
    }

    public static function enBinaire(PhpWord $word): string
    {
        $fichier = tempnam(sys_get_temp_dir(), 'pssfp-docx-');
        if ($fichier === false) {
            throw new RuntimeException('Impossible de créer le fichier temporaire du document Word.');
        }

        try {
            IOFactory::createWriter($word, 'Word2007')->save($fichier);

            return (string) file_get_contents($fichier);
        } finally {
            @unlink($fichier);
        }
    }
}
